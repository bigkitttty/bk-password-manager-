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

namespace App\Service\Groups;

use App\Error\Exception\CustomValidationException;
use App\Error\Exception\ValidationException;
use App\Model\Entity\GroupsUser;
use App\Model\Entity\Permission;
use App\Model\Table\GroupsUsersTable;
use App\Service\GroupsUsers\GroupsUsersCreateService;
use App\Utility\UserAccessControl;
use Cake\ORM\TableRegistry;
use Cake\Utility\Hash;

class GroupsUpdateGroupUsersService
{
    /**
     * @var GroupsUsersCreateService
     */
    private $groupsUsersCreateService;

    /**
     * @var GroupsUsersTable
     */
    private $groupsUsersTable;

    /**
     * Instantiate the service.
     */
    public function __construct()
    {
        $this->groupsUsersCreateService = new GroupsUsersCreateService();
        $this->groupsUsersTable = TableRegistry::getTableLocator()->get('GroupsUsers');
    }

    /**
     * Update groups users of a group.
     *
     * @param UserAccessControl $uac The current user
     * @param string $groupId The target group
     * @param array $changes The list of permissions changes to apply
     * @return array
     * [
     *   added => <array> List of added group users
     *   deleted => <array> List of deleted group users
     *   updated => <array> List of updated group users
     * ]
     * @throws \Exception
     */
    public function updateGroupUsers(UserAccessControl $uac, string $groupId, array $changes = [])
    {
        $addedGroupsUsers = [];
        $updatedGroupsUsers = [];
        $removedGroupsUsers = [];

        $this->groupsUsersTable->getConnection()->transactional(function () use ($uac, $groupId, $changes, &$addedGroupsUsers, &$updatedGroupsUsers, &$removedGroupsUsers) {
            list($addedGroupsUsers, $updatedGroupsUsers, $removedGroupsUsers) = $this->update($uac, $groupId, $changes);
            $this->assertAtLeastOneGroupManager($groupId);
        });

        return [
            'added' => $addedGroupsUsers,
            'removed' => $removedGroupsUsers,
            'updated' => $updatedGroupsUsers,
        ];
    }

    /**
     * Internal function that update the group users of a group.
     *
     * @param UserAccessControl $uac The current user
     * @param string $groupId The target group
     * @param array $changes The list of permissions changes to apply
     * @return array
     * [
     *   0 => <array> List of added group users
     *   1 => <array> List of updated group users
     *   2 => <array> List of deleted group users
     * ]
     * @throws \Exception
     */
    private function update(UserAccessControl $uac, string $groupId, array $changes = [])
    {
        $addedGroupsUsers = [];
        $updatedGroupsUsers = [];
        $deletedGroupsUsers = [];

        foreach ($changes as $rowIndex => $row) {
            $groupUserId = Hash::get($row, 'id', null);

            // A new groupUser is provided when no id is found in the raw data.
            if (is_null($groupUserId)) {
                $addedGroupsUsers[] = $this->addGroupUser($uac, $rowIndex, $groupId, $row);
            } else {
                // If a property delete is found and set to true, then delete the group user.
                // Otherwise update it.
                $groupUser = $this->getGroupUser($rowIndex, $groupId, $groupUserId);
                $delete = Hash::get($row, 'delete');
                if ($delete) {
                    $deletedGroupsUsers[] = $this->deleteGroupUser($groupUser);
                } else {
                    $updatedGroupsUsers[] = $this->updateGroupUser($uac, $rowIndex, $groupUser, $row);
                }
            }
        }

        return [$addedGroupsUsers, $updatedGroupsUsers, $deletedGroupsUsers];
    }

    /**
     * Add a group user to a group.
     *
     * @param UserAccessControl $uac The operator
     * @param int $rowIndexRef The row index in the request data
     * @param string $groupId The target group
     * @param array $data The group user data
     * @return GroupsUser
     * @throws \Exception
     */
    private function addGroupUser(UserAccessControl $uac, int $rowIndexRef, string $groupId, array $data)
    {
        $permissionData = [
            'group_id' => $groupId,
            'user_id' => Hash::get($data, 'user_id'),
            'is_admin' => Hash::get($data, 'is_admin', false),
        ];

        try {
            return $this->groupsUsersCreateService->create($uac, $permissionData);
        } catch (ValidationException $e) {
            $errors = [$rowIndexRef => $e->getErrors()];
            $this->handleValidationErrors($errors);
        }
    }

    /**
     * Handle groups users validation errors.
     *
     * @param array $errors The list of errors
     * @return void
     * @throws ValidationException If the provided data does not validate.
     */
    private function handleValidationErrors(array $errors = [])
    {
        if (!empty($errors)) {
            throw new CustomValidationException(__('Could not validate group user data.'), $errors, $this->groupsUsersTable);
        }
    }

    /**
     * Retrieve a group user.
     *
     * @param int $rowIndexRef The row index in the request data
     * @param string $groupId The target group
     * @param string $groupUserId The target group user
     * @return Permission
     */
    private function getGroupUser(int $rowIndexRef, string $groupId, string $groupUserId)
    {
        $groupUser = $this->groupsUsersTable->findByIdAndGroupId($groupUserId, $groupId)->first();
        if (!$groupUser) {
            $errors = [$rowIndexRef => ['id' => ['exists' => 'The group user does not exist.']]];
            $this->handleValidationErrors($errors);
        }

        return $groupUser;
    }

    /**
     * Delete a group user.
     *
     * @param GroupsUser $groupsUser The target group user
     * @return GroupsUser
     */
    private function deleteGroupUser(GroupsUser $groupsUser)
    {
        $this->groupsUsersTable->delete($groupsUser);

        return $groupsUser;
    }

    /**
     * Update a group user.
     *
     * @param UserAccessControl $uac The operator
     * @param int $rowIndexRef The row index in the request data
     * @param GroupsUser $groupUser The target group user
     * @param array $data The group user data.
     * @return GroupsUser
     */
    private function updateGroupUser(UserAccessControl $uac, int $rowIndexRef, GroupsUser $groupUser, array $data)
    {
        // If role of the user doesn't change, nothing to do.
        $updatedIsAdmin = Hash::get($data, 'is_admin');
        if ($groupUser->is_admin === $updatedIsAdmin) {
            return $groupUser;
        }

        $data['modified_by'] = $uac->userId();

        $patchEntityOptions = ['accessibleFields' => ['is_admin' => true, 'modified_by' => true]];
        $groupUser = $this->groupsUsersTable->patchEntity($groupUser, $data, $patchEntityOptions);
        if ($groupUser->hasErrors()) {
            $errors = [$rowIndexRef => $groupUser->getErrors()];
            $this->handleValidationErrors($errors);
        }

        $this->groupsUsersTable->save($groupUser);
        if ($groupUser->hasErrors()) {
            $errors = [$rowIndexRef => $groupUser->getErrors()];
            $this->handleValidationErrors($errors);
        }

        return $groupUser;
    }

    /**
     * Assert that a group has at least one group manager
     *
     * @param string $groupId The target group
     * @return void
     * @throws CustomValidationException If the group doesn't have at least one group manager.
     */
    private function assertAtLeastOneGroupManager(string $groupId)
    {
        $groupManagersCount = $this->groupsUsersTable->find()
            ->where([
                'group_id' => $groupId,
                'is_admin' => true,
            ])
            ->count();

        if ($groupManagersCount < 1) {
            $errors = ['at_least_one_group_manager' => 'At least one group manager must be provided.'];
            $this->handleValidationErrors($errors);
        }
    }
}
