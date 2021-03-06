<?php
/**
 * Authors: Nickolay U. Kofanov, Artem Paliy
 * Company: CodeTiburon
 * Last Edited: 25.06.2013
 */
namespace Zf2Acl\Permissions\Acl;

use \Zend\Permissions\Acl\Acl as ZendAcl;
use \Zend\Mvc\Router\RouteMatch;
use \Zend\Http\PhpEnvironment\Request as HttpRequest;
use \Zend\Mvc\ModuleRouteListener;
use \Zend\ServiceManager\ServiceManagerAwareInterface;
use \Zend\ServiceManager\ServiceManager;
use \Zend\Permissions\Acl\Role\GenericRole;

class Acl extends ZendAcl implements ServiceManagerAwareInterface
{
    /**
     * Default Role
     */
    const DEFAULT_ROLE = 'guest';

    /**
     * @var ServiceManager
     */
    protected $serviceManager;
    protected $defaultRole;
    protected $currentUser;
    protected $roleKey = 'role';

    public function __construct()
    {
        $this->defaultRule =& $this->rules['allResources']['allRoles']['allPrivileges']['type'];
    }

    /**
     * @param ServiceManager $serviceManager
     */
    public function setServiceManager(ServiceManager $serviceManager)
    {
        $this->serviceManager = $serviceManager;
        $config = $serviceManager->get('Config');

        if (isset($config['acl'])) {
           $this->setOptions($config['acl']);
        }
    }

    public function setRoleKey($rolekey)
    {
        $this->roleKey = $rolekey;
    }

    public function getCurrentUser()
    {
        if (null === $this->currentUser) {
            $this->currentUser = $this->serviceManager->get('CurrentUser');
        }
        return $this->currentUser;
    }

    public function isAllowedDefault()
    {
        return self::TYPE_ALLOW == $this->defaultRule;
    }

    public function allowDefault()
    {
        $this->defaultRule = self::TYPE_ALLOW;
        return $this;
    }

    public function denyDefault()
    {
        $this->defaultRule = self::TYPE_DENY;
        return $this;
    }

    public function setOptions($options)
    {
        if (isset($options['default'])) {
            $default =& $options['default'];

            if (isset($default['role'])) {
                $this->setDefaultRole($default['role']);
            }

            if (isset($default['access'])) {
                if ('allow' == strtolower(trim($default['access']))) {
                    $this->allowDefault();
                } else {
                    $this->denyDefault();
                }
            }
        }

        if (isset($options['roleKey'])) {
            $this->setRoleKey($options['roleKey']);
        }

        if (isset($options['roles'])) {
            $this->addRoles($options['roles']);
        }

        if (isset($options['resources'])) {
            $this->addResources($options['resources']);
        }
    }

    public function addRoles(array $roles)
    {
        foreach ($roles as $roleName => $roleParents) {
            if ($this->hasRole($roleName)) {
                continue;
            }
            $roleParents = is_array($roleParents) ? $roleParents : explode(',', $roleParents);
            foreach ($roleParents as &$parent) {
                $parent = trim($parent);
            }
            $this->addRole(new GenericRole($roleName), $roleParents);
        }
    }

    /**
     * adding resource chain like 'base\parent\child'
     * is similarly to:
     *    addResource(base, null);
     *    addResource(base\parent, base);
     *    addResource(base\parent\child, base:parent);
     *
     */
    public function addResourceChain($resource)
    {
        $pos = strrpos($resource, '\\');
        if ($pos !== false) {
            $parent = substr($resource, 0, $pos);
            if (!$this->hasResource($parent)) {
                $this->addResourceChain($parent);
            }
        } else {
            $parent = null;
        }
        $this->addResource($resource, $parent);
    }

    public function getCurrentRole()
    {
        if ((null !== $this->getCurrentUser()) &&
            ($user = $this->getCurrentUser()) &&
            ($role = $user->{$this->roleKey})
        ) {
            return $role;
        }
        return $this->getDefaultRole();
    }

    public function getDefaultRole()
    {
        return self::DEFAULT_ROLE;
    }

    public function setDefaultRole($role)
    {
        $this->defaultRole = $role;
        if (!$this->hasRole($role)) {
            $this->addRole($role);
        }
    }

    public function isAllowedUri($uri)
    {
        $request = new HttpRequest($uri);
        $router = $this->serviceManager->get('router');
        $routeMatch = $router->route($request);
        if (!$routeMatch instanceof RouteMatch) {
            return false;
        }
        return $this->isAllowedRouteMatch($routeMatch);
    }

    public function isAllowedRouteMatch(RouteMatch $routeMatch)
    {
        $module = $routeMatch->getParam(ModuleRouteListener::MODULE_NAMESPACE);
        $controller = $routeMatch->getParam(ModuleRouteListener::ORIGINAL_CONTROLLER);
        if ( !$controller && !($controller = $routeMatch->getParam('controller')) ) {
            return false;
        }

        $action = $routeMatch->getParam('action');

        $module = $module ? substr($module, 0, strpos($module, '\\')) : substr($controller, 0, strpos($controller, '\\'));

        if (false !== ($pos = strrpos($controller, '\\'))) {
            $controller = substr($controller, 1 + $pos);
        }

        $resource = 'mvc\\';
        $resource .= $module;
        $resource .= '\\';
        $resource .= $controller;
        $resource .= '\\';
        $resource .= !empty($action)?$action:'';
        $resource  = strtolower($resource);

        if (!$this->hasResource($resource)) {
            $this->addResourceChain($resource);
        }

        return $this->isAllowed($this->getCurrentRole(), $resource);
    }

    protected function addResources($resources, $parent = null)
    {
        foreach ($resources as $resource => $params) {
            if ($parent) {
                $resource = $parent . '\\' . $resource;
            }
            $resource = strtolower($resource);

            if (!$this->hasResource($resource)) {
                $this->addResource($resource, $parent);
            }

            // if default access is allow then deny-rule has a higher priority
            if ($this->isAllowedDefault()) {
                $this->parseAllow($resource, $params);
                $this->parseDeny($resource, $params);
            } else { // if default access is deny then allow-rule has a higher priority
                $this->parseDeny($resource, $params);
                $this->parseAllow($resource, $params);
            }

            if (isset($params['children'])) {
                $this->addResources($params['children'], $resource);
            }
        }
    }

    protected function parseAllow($resource, $params)
    {
        if (!isset($params['allow'])) {
            return;
        }
        $roles = is_array($params['allow']) ? $params['allow'] : explode(',', $params['allow']);
        foreach ($roles as $role) {
            $this->allow(trim($role), $resource);
        }
    }

    protected function parseDeny($resource, $params)
    {
        if (!isset($params['deny'])) {
            return;
        }
        $roles = is_array($params['deny']) ? $params['deny'] : explode(',', $params['deny']);
        foreach ($roles as $role) {
            $this->deny(trim($role), $resource);
        }
    }
}
