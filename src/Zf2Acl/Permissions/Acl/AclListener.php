<?php
/**
 * Authors: Nickolay U. Kofanov, Artem Paliy
 * Company: CodeTiburon
 * Last Edited: 25.06.2013
 */
namespace Zf2Acl\Permissions\Acl;

use \Zend\Mvc\MvcEvent as MvcEvent;
use \Zf2Acl\Permissions\Acl\Acl;
use \Zend\EventManager;
use \Zf2Acl\Mvc\Exception\AccessDeniedException;
use \Zf2Acl\Mvc\View\Http\AccessDeniedStrategy;
use \Zend\ServiceManager\ServiceManagerAwareInterface;
use \Zend\ServiceManager\ServiceManager;

class AclListener implements EventManager\ListenerAggregateInterface, ServiceManagerAwareInterface
{
    /**
     * @var ServiceManager
     */
    protected $serviceManager;
    /**
     * @var Acl
     */
    protected $acl;

    /**
     * @var AccessDeniedStrategy
     */
    protected $accessDeniedStrategy;
	
	/**
	* @var strategyName
	*/
	protected $strategyName = 'AccessDeniedStrategy';

    /**
     * @var array
     */
    protected $listeners = array();

    /**
     * @param ServiceManager $serviceManager
     */
    public function setServiceManager(ServiceManager $serviceManager)
    {
        $this->serviceManager = $serviceManager;
    }

    /**
     * Attach to an event manager
     *
     * @param  EventManager\EventManagerInterface $events
     * @return void
     */
    public function attach(EventManager\EventManagerInterface $events)
    {
        $sharedEvents = $events->getSharedManager();
        $accessDeniedStrategy = $this->getAccessDeniedStrategy();

        $this->listeners[] = $sharedEvents->attach('Zend\Stdlib\DispatchableInterface', MvcEvent::EVENT_DISPATCH, array($this, 'onDispatch'), 1000);
        $events->attach($accessDeniedStrategy);
    }

    /**
     * Detach all our listeners from the event manager
     *
     * @param  EventManager\EventManagerInterface $events
     * @return void
     */
    public function detach(EventManager\EventManagerInterface $events)
    {
        $sharedEvents = $events->getSharedManager();
        foreach ($this->listeners as $index => $listener) {
            if ($sharedEvents->detach('Zend\Stdlib\DispatchableInterface', $listener)) {
                unset($this->listeners[$index]);
            }
        }
        $events->detach($this->getAccessDeniedStrategy());
    }

    /**
     * preDispatch Event Handler
     *
     * @param \Zend\Mvc\MvcEvent $event
     * @throws AccessDeniedException
     */
    public function onDispatch(MvcEvent $event)
    {
        if (!$this->getAcl()->isAllowedRouteMatch($event->getRouteMatch())) {
            throw new AccessDeniedException();
        }
    }

    /**
     * @return AccessDeniedStrategy
     */
    public function getAccessDeniedStrategy()
    {
        if (null === $this->accessDeniedStrategy) {
			$config = $this->serviceManager->get('Config');
			$this->strategyName = isset($config['accessDeniedStrategy']['name']) ? 
												$config['accessDeniedStrategy']['name'] : $this->strategyName;
            $this->accessDeniedStrategy = $this->serviceManager->get($this->strategyName);
        }
        return $this->accessDeniedStrategy;
    }

    /**
     * @return Acl
     */
    public function getAcl()
    {
        if (null === $this->acl) {
            $this->acl = $this->serviceManager->get('Acl');
        }
        return $this->acl;
    }
}
