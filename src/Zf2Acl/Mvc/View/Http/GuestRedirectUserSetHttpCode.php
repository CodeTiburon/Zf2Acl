<?php
/**
 * Authors: Nickolay U. Kofanov, Artem Paliy
 * Company: CodeTiburon
 * Last Edited: 25.06.2013
 */
namespace Zf2Acl\Mvc\View\Http;

use \Zf2Acl\Mvc\Exception\AccessDeniedException;
use \Zend\EventManager\EventManagerInterface;
use \Zend\EventManager\ListenerAggregateInterface;
use \Zend\Http\Response as HttpResponse;
use \Zend\Mvc\MvcEvent;
use \Zend\Stdlib\ResponseInterface as Response;
use \Zend\View\Model\ViewModel;
use \Zend\Mvc\ModuleRouteListener;
use \Zend\Mvc\Application;
use \Zend\ServiceManager\ServiceManager;
use \Zend\ServiceManager\ServiceManagerAwareInterface;

class GuestRedirectUserSetHttpCode implements ListenerAggregateInterface, ServiceManagerAwareInterface
{
    /**
     * @var \Zend\Stdlib\CallbackHandler[]
     */
    protected $listeners = array();

    /**
     * Whether or not to display exceptions related to the 404 condition
     *
     * @var bool
     */
    protected $deniedTemplate = 'error';

    protected $statusCode = '404';

    protected $routeName = 'home';

    /**
     * Set service manager
     *
     * @param ServiceManager $serviceManager
     */
    public function setServiceManager(ServiceManager $serviceManager)
    {
        $config = $serviceManager->get('Config');

        if (isset($config['view_manager'])) {

            $accessDeniedTemplate = $this->statusCode = isset($config['accessDeniedStrategy']['code']) ? $config['accessDeniedStrategy']['code'] : 403;
            $template = isset($config['accessDeniedStrategy']['template']) ? $config['accessDeniedStrategy']['template'] : 'access_denied_template';
            $this->routeName = isset($config['accessDeniedStrategy']['route']) ? $config['accessDeniedStrategy']['route'] : $this->routeName;

            $config = $config['view_manager'];

            if (isset($config[$template])) {
                $accessDeniedTemplate = $config[$template];
            }

            $this->setDeniedTemplate($accessDeniedTemplate);
        }
    }

    /**
     * Attach the aggregate to the specified event manager
     *
     * @param  EventManagerInterface $events
     *
     * @return void
     */
    public function attach(EventManagerInterface $events)
    {
        $this->listeners[] = $events->attach(MvcEvent::EVENT_DISPATCH, array($this, 'prepareDeniedViewModel'), -90);
        /* set higher priority then ExceptionStrategy */
        $this->listeners[] = $events->attach(MvcEvent::EVENT_DISPATCH_ERROR, array($this, 'detectDeniedError'), 10);
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_DISPATCH_ERROR,
            array($this, 'prepareDeniedViewModel'),
            10
        );
    }

    /**
     * Detach aggregate listeners from the specified event manager
     *
     * @param  EventManagerInterface $events
     *
     * @return void
     */
    public function detach(EventManagerInterface $events)
    {
        foreach ($this->listeners as $index => $listener) {
            if ($events->detach($listener)) {
                unset($this->listeners[$index]);
            }
        }
    }

    /**
     * Get template for not found conditions
     *
     * @param  string $deniedTemplate
     *
     * @return AccessDeniedStrategy
     */
    public function setDeniedTemplate($deniedTemplate)
    {
        $this->deniedTemplate = (string)$deniedTemplate;
        return $this;
    }

    /**
     * Get template for not found conditions
     *
     * @return string
     */
    public function getDeniedTemplate()
    {
        return $this->deniedTemplate;
    }

    /**
     * Detect if an error is a 404 condition
     *
     * If a "controller not found" or "invalid controller" error type is
     * encountered, sets the response status code to 404.
     *
     * @param  MvcEvent $e
     *
     * @return void
     */
    public function detectDeniedError(MvcEvent $e)
    {
        $error = $e->getError();
        if (empty($error) || $error !== Application::ERROR_EXCEPTION) {
            return;
        }

        $ex = $e->getParam('exception');
        if ($ex instanceof AccessDeniedException) {
            $response = $e->getResponse();
            if (!$response) {
                $response = new HttpResponse();
                $e->setResponse($response);
            }
            $response->setStatusCode($this->statusCode);
            /* prevent ExceptionStrategy handler */
            $e->setError(null);
        }
    }

    /**
     * Create and return a statusCode view model
     *
     * @param  MvcEvent $e
     *
     * @return void
     */
    public function prepareDeniedViewModel(MvcEvent $e)
    {
        $serviceManager = $e->getApplication()->getServiceManager();
        $acl = $serviceManager->get('Acl');
        $currentUser = $acl->getCurrentUser();
        $routeMatch = $e->getRouteMatch();

        if (!$acl->isAllowedRouteMatch($routeMatch)) {

            $routeName = $routeMatch->getMatchedRouteName();

            if ($routeName !== $this->routeName) {
                $target = $e->getTarget();

                // Do what ever you want to check the user's identity
                $url = $e->getRouter()->assemble(array(), array('name' => $this->routeName));

                $response = $e->getResponse();
                $response->setHeaders($response->getHeaders()->addHeaderLine('Location', $url));
                $response->setStatusCode(200);
                $response->sendHeaders();
                exit();
            }
        } else {
            $vars = $e->getResult();
            if ($vars instanceof Response) {
                // Already have a response as the result
                return;
            }

            $response = $e->getResponse();
            if ($response->getStatusCode() != $this->statusCode) {
                // Only handle statusCode responses
                return;
            }

            if (!$vars instanceof ViewModel) {
                $model = new ViewModel();
                if (is_string($vars)) {
                    $model->setVariable('message', $vars);
                } else {
                    $model->setVariable('message', 'Access denied.');
                }
            } else {
                $model = $vars;
                if ($model->getVariable('message') === null) {
                    $model->setVariable('message', 'Access denied.');
                }
            }

            $model->setTemplate($this->getDeniedTemplate());

            $this->injectMvcParams($model, $e);

            $e->setResult($model);
        }
    }

    /**
     * Inject module, controller and action into the model
     *
     * @param  ViewModel $model
     * @param  MvcEvent  $e
     *
     * @return void
     */
    protected function injectMvcParams($model, $e)
    {
        $routeMatch = $e->getRouteMatch();

        $module = $e->getParam('module-name');
        if (!$module) {
            $module = $routeMatch->getParam(ModuleRouteListener::MODULE_NAMESPACE);
        }

        $controller = $e->getParam('controller-name');
        if (!$controller) {
            $controller = $routeMatch->getParam(ModuleRouteListener::ORIGINAL_CONTROLLER);
            if (!$controller) {
                $controller = $routeMatch->getParam('controller');
            }
        }

        $action = $e->getParam('action-name');
        if (!$action) {
            $action = $routeMatch->getParam('action');
        }

        $model->setVariable('module', $module);
        $model->setVariable('controller', $controller);
        $model->setVariable('action', $action);
    }
}
