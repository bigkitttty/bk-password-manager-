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

namespace App\Service\Permissions;

use App\Error\Exception\ValidationException;
use App\Model\Entity\Permission;
use App\Model\Table\PermissionsTable;
use App\Utility\UserAccessControl;
use Cake\ORM\TableRegistry;

class PermissionsCreateService
{
    /**
     * @var PermissionsTable
     */
    private $permissionsTable;

    /**
     * Instantiate the service.
     */
    public function __construct()
    {
        $this->permissionsTable = TableRegistry::getTableLocator()->get('Permissions');
    }

    /**
     * Create a permission.
     *
     * @param UserAccessControl $uac The user at the origin of the operation
     * @param array $data The permission data
     * [
     *   string $aco The permission aco type
     *   string $aco_foreign_key The permission aco instance id
     *   string $aro The permission aro type
     *   string $aro_foreign_key The permission aro instance id
     *   int $type The permission type
     * ]
     * @return Permission
     * @throws \Exception
     */
    public function create(UserAccessControl $uac, array $data = [])
    {
        $permission = null;
        $this->permissionsTable->getConnection()->transactional(function () use (&$permission, $uac, $data) {
            $permission = $this->createPermission($uac, $data);
        });

        return $permission;
    }

    /**
     * Create and save a permission.
     *
     * @param UserAccessControl $uac The user at the origin of the operation
     * @param array $data The permission data
     * [
     *   string $aco The permission aco type
     *   string $aco_foreign_key The permission aco instance id
     *   string $aro The permission aro type
     *   string $aro_foreign_key The permission aro instance id
     *   int $type The permission type
     * ]
     * @return Permission
     */
    private function createPermission(UserAccessControl $uac, array $data = [])
    {
        $permission = $this->buildPermissionEntity($uac, $data);
        $this->handlePermissionValidationErrors($permission);
        $this->permissionsTable->save($permission);
        $this->handlePermissionValidationErrors($permission);

        return $permission;
    }

    /**
     * Build the permission entity.
     *
     * @param UserAccessControl $uac The user at the origin of the operation
     * @param array $data The permission data
     * [
     *   string $aco The permission aco type
     *   string $aco_foreign_key The permission aco instance id
     *   string $aro The permission aro type
     *   string $aro_foreign_key The permission aro instance id
     *   int $type The permission type
     * ]
     * @return Permission
     */
    private function buildPermissionEntity(UserAccessControl $uac, array $data = [])
    {
        $operatorId = $uac->userId();
        $data = array_merge([
            'created_by' => $operatorId,
            'modified_by' => $operatorId,
        ], $data);
        $accessibleFields = [
            'aco' => true,
            'aco_foreign_key' => true,
            'aro' => true,
            'aro_foreign_key' => true,
            'type' => true,
            'created_by' => true,
            'modified_by' => true,
        ];

        return $this->permissionsTable->newEntity($data, ['accessibleFields' => $accessibleFields]);
    }

    /**
     * Handle permission validation errors.
     *
     * @param Permission $permission The permission
     * @return void
     */
    private function handlePermissionValidationErrors(Permission $permission)
    {
        $errors = $permission->getErrors();
        if (!empty($errors)) {
            throw new ValidationException(__('Could not validate the permission data.'), $permission, $this->permissionsTable);
        }
    }
}
