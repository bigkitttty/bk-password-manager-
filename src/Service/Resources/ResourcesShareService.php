<?php
/**
 * Passbolt ~ Open source password manager for teams
 * Copyright (c) Passbolt SA (https://www.passbolt.com)
 *
 * Licensed under GNU Affero General Public License version 3 of the or any later version.
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Passbolt SA (https://www.passbolt.com)
 * @license       https://opensource.org/licenses/AGPL-3.0 AGPL License
 * @link          https://www.passbolt.com Passbolt(tm)
 * @since         2.13.0
 */

namespace App\Service\Resources;

use App\Error\Exception\CustomValidationException;
use App\Error\Exception\ValidationException;
use App\Model\Entity\Permission;
use App\Model\Entity\Resource;
use App\Model\Table\FavoritesTable;
use App\Model\Table\GroupsUsersTable;
use App\Model\Table\PermissionsTable;
use App\Model\Table\ResourcesTable;
use App\Model\Table\UsersTable;
use App\Service\Permissions\PermissionsGetUsersIdsHavingAccessToService;
use App\Service\Permissions\PermissionsUpdatePermissionsService;
use App\Service\Permissions\UserHasPermissionService;
use App\Service\Secrets\SecretsUpdateSecretsService;
use App\Utility\UserAccessControl;
use Cake\Event\Event;
use Cake\Http\Exception\NotFoundException;
use Cake\ORM\TableRegistry;

class ResourcesShareService
{
    /**
     * @var FavoritesTable
     */
    private $favoritesTable;

    /**
     * @var GroupsUsersTable
     */
    private $groupsUsersTable;

    /**
     * @var PermissionsGetUsersIdsHavingAccessToService
     */
    private $permissionsGetUsersIdsHavingAccessToService;

    /**
     * @var PermissionsUpdatePermissionsService
     */
    private $permissionsUpdatePermissionsService;

    /**
     * @var ResourcesTable
     */
    private $resourcesTable;

    /**
     * @var SecretsUpdateSecretsService
     */
    private $secretsUpdateSecretsService;

    /**
     * @var UserHasPermissionService
     */
    private $userHasPermissionService;

    /**
     * @var UsersTable
     */
    private $usersTable;

    /**
     * Instantiate the service.
     */
    public function __construct()
    {
        $this->favoritesTable = TableRegistry::getTableLocator()->get('Favorites');
        $this->groupsUsersTable = TableRegistry::getTableLocator()->get('GroupsUsers');
        $this->permissionsGetUsersIdsHavingAccessToService = new PermissionsGetUsersIdsHavingAccessToService();
        $this->permissionsUpdatePermissionsService = new PermissionsUpdatePermissionsService();
        $this->resourcesTable = TableRegistry::getTableLocator()->get('Resources');
        $this->secretsUpdateSecretsService = new SecretsUpdateSecretsService();
        $this->userHasPermissionService = new UserHasPermissionService();
        $this->usersTable = TableRegistry::getTableLocator()->get('Users');
    }

    /**
     * Share a resource by updating its permissions.
     *
     * @param UserAccessControl $uac The current user
     * @param string $resourceId The target resource
     * @param array $changes The list of permissions changes to apply
     * @param array $secrets The list of new secrets to add
     * @return \App\Model\Entity\Resource
     * @throws \Exception
     */
    public function share(UserAccessControl $uac, string $resourceId, array $changes = [], array $secrets = [])
    {
        $resource = $this->getResource($resourceId);

        $this->resourcesTable->getConnection()->transactional(function () use ($uac, $resource, $changes, $secrets) {
            $updatePermissionsResult = $this->updatePermissions($uac, $resource, $changes);
            $this->updateSecrets($uac, $resource, $secrets);
            $this->postAccessesGranted($uac, $updatePermissionsResult['added']);
            $this->postAccessesRevoked($uac, $resource, $updatePermissionsResult['removed']);
        });

        return $resource;
    }

    /**
     * Retrieve the resource.
     *
     * @param string $resourceId The resource identifier to retrieve.
     * @return \App\Model\Entity\Resource
     * @throws NotFoundException If the resource does not exist.
     */
    private function getResource(string $resourceId)
    {
        $resource = $this->resourcesTable->findByIdAndDeleted($resourceId, false)->first();
        if (empty($resource)) {
            throw new NotFoundException(__('The resource does not exist.'));
        }

        return $resource;
    }

    /**
     * Update the permissions of a resource.
     *
     * @param UserAccessControl $uac The current user
     * @param \App\Model\Entity\Resource $resource The target resource
     * @param array $changes The list of permissions changes to apply
     * @return array
     * [
     *   added => <array> List of added permissions
     *   deleted => <array> List of deleted permissions
     *   updated => <array> List of updated permissions
     * ]
     * @throws \Exception If something unexpected occurred
     */
    private function updatePermissions(UserAccessControl $uac, \App\Model\Entity\Resource $resource, array $changes)
    {
        try {
            return $this->permissionsUpdatePermissionsService->updatePermissions($uac, PermissionsTable::RESOURCE_ACO, $resource->id, $changes);
        } catch (CustomValidationException $e) {
            $resource->setError('permissions', $e->getErrors());
            $this->handleValidationErrors($resource);
        }
    }

    /**
     * Handle resource validation errors.
     *
     * @param \App\Model\Entity\Resource $resource The target resource
     * @return void
     * @throws ValidationException If the provided data does not validate.
     */
    private function handleValidationErrors(\App\Model\Entity\Resource $resource)
    {
        $errors = $resource->getErrors();
        if (!empty($errors)) {
            throw new ValidationException(__('Could not validate resource data.'), $resource, $this->resourcesTable);
        }
    }

    /**
     * Update the secrets.
     *
     * @param UserAccessControl $uac The operator
     * @param resource $resource The target resource
     * @param array $data The list of secrets to add
     * @return void
     * @throws \Exception
     */
    private function updateSecrets(UserAccessControl $uac, Resource $resource, array $data)
    {
        try {
            $this->secretsUpdateSecretsService->updateSecrets($uac, $resource->id, $data);
        } catch (CustomValidationException $e) {
            $resource->setError('secrets', $e->getErrors());
            $this->handleValidationErrors($resource);
        }
    }

    /**
     * Post accesses granted.
     *
     * @param UserAccessControl $uac The operator
     * @param array $addedPermissions The list of added permissions
     * @return void
     */
    private function postAccessesGranted(UserAccessControl $uac, array $addedPermissions = [])
    {
        foreach ($addedPermissions as $addedPermission) {
            $this->notifyAccessGranted($uac, $addedPermission);
        }
    }

    /**
     * Trigger an event to notify other components about the granted permissions.
     *
     * @param UserAccessControl $uac The operator
     * @param Permission $permission The granted permission
     * @return void
     */
    private function notifyAccessGranted(UserAccessControl $uac, Permission $permission)
    {
        $eventData = ['permission' => $permission, 'accessControl' => $uac];
        $event = new Event('Service.ResourcesShare.afterAccessGranted', $this, $eventData);
        $this->resourcesTable->getEventManager()->dispatch($event);
    }

    /**
     * Post accesses revoked.
     *
     * @param UserAccessControl $uac The operator
     * @param resource $resource The target permissions
     * @param array $deletedPermissions The list of deleted permissions
     * @return void
     */
    private function postAccessesRevoked(UserAccessControl $uac, Resource $resource, array $deletedPermissions = [])
    {
        foreach ($deletedPermissions as $deletedPermission) {
            $this->notifyAccessRevoked($uac, $deletedPermission);
            if ($deletedPermission->aro === PermissionsTable::GROUP_ARO) {
                $this->postGroupAccessRevoked($resource, $deletedPermission->aro_foreign_key);
            } else {
                $this->postUserAccessRevoked($resource, $deletedPermission->aro_foreign_key);
            }
        }
    }

    /**
     * Trigger an event to notify other components about the revoked permissions.
     *
     * @param UserAccessControl $uac The operator
     * @param Permission $permission The revoked permission
     * @return void
     */
    private function notifyAccessRevoked(UserAccessControl $uac, Permission $permission)
    {
        $eventData = ['permission' => $permission, 'accessControl' => $uac];
        $event = new Event('Service.ResourcesShare.afterAccessRevoked', $this, $eventData);
        $this->resourcesTable->getEventManager()->dispatch($event);
    }

    /**
     * Post group access revoked treatment.
     *
     * @param resource $resource The target resource
     * @param string $groupId The target group
     * @return void
     * @throws Exception
     */
    private function postGroupAccessRevoked(Resource $resource, string $groupId)
    {
        $grousUsersIds = $this->groupsUsersTable->findByGroupId($groupId)->extract('user_id')->toArray();
        foreach ($grousUsersIds as $groupUserId) {
            $this->postUserAccessRevoked($resource, $groupUserId);
        }
    }

    /**
     * Post user access revoked treatment. If user doesn't have anymore access to a resource:
     * - Remove the user secrets for this resource.
     * - Remove the user favorites for this resource.
     * - Trigger an event to notify other plugin about the revoked access.
     *
     * @param resource $resource The target resource
     * @param string $userId The target user
     * @return void
     */
    private function postUserAccessRevoked(Resource $resource, string $userId)
    {
        // If the user still has access to the folder, don't alter the user tree.
        $hasAccess = $this->userHasPermissionService->check(PermissionsTable::FOLDER_ACO, $resource->id, $userId);
        if ($hasAccess) {
            return;
        }

        $this->resourcesTable->deleteLostAccessAssociatedData($resource->id, [$userId]);
    }

    /**
     * Simulate the share of a resource in order to retrieve the users that will require the secret to be encrypted for.
     *
     * The function returns an associative array that contains a list of new users who will have access to the resource,
     * and a list of users who will lose their access. These lists will be used to encrypt the secrets for the users
     * who get access and to remove the secrets of the users who lost their access.
     *
     * [
     *   'added' => [uuid],
     *   'removed' => [uuid]
     * ]
     *
     * @param UserAccessControl $uac The current user
     * @param string $resourceId The target resource
     * @param array $changes The list of changes to apply
     * @return array
     * @throws \Exception
     */
    public function shareDryRun(UserAccessControl $uac, string $resourceId, array $changes = [])
    {
        $resource = $this->getResource($resourceId);
        $userIdsHavingAccessBefore = $this->permissionsGetUsersIdsHavingAccessToService->getUsersIdsHavingAccessTo($resourceId);
        $userIdsHavingAccessAfter = [];

        $this->resourcesTable->getConnection()->transactional(function () use ($uac, $resource, $changes, &$userIdsHavingAccessAfter) {
            $this->updatePermissions($uac, $resource, $changes);
            $userIdsHavingAccessAfter = $this->permissionsGetUsersIdsHavingAccessToService->getUsersIdsHavingAccessTo($resource->id);

            // Don't commit the transaction.
            return false;
        });

        // Extract the users that will require the secrets to be encrypted for.
        $usersIdsToAddSecretFor = array_diff($userIdsHavingAccessAfter, $userIdsHavingAccessBefore);
        // Extract the users the secrets will be deleted for.
        $usersIdsToDeleteSecretFor = array_diff($userIdsHavingAccessBefore, $userIdsHavingAccessAfter);

        return [
            'added' => $usersIdsToAddSecretFor,
            'deleted' => $usersIdsToDeleteSecretFor,
        ];
    }
}
