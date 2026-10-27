<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Model\User\Role;
use Nette\Security\Permission;

final class AuthorizatorFactory
{
	public const string Guest = 'guest';


	public function create(): Permission
	{
		$acl = new Permission();
		$acl->addRole(self::Guest);
		$acl->addRole(Role::Editor->value);
		$acl->addRole(Role::Admin->value, Role::Editor->value);

		foreach (Resource::cases() as $resource) {
			$acl->addResource($resource->value);
		}

		$acl->allow(Role::Editor->value, Resource::Dashboard->value);
		$acl->allow(Role::Admin->value, [Resource::Users->value, Resource::Settings->value]);

		return $acl;
	}
}
