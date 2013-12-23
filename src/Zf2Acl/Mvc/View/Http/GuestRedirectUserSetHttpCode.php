<?php
/**
 * Authors: Nickolay U. Kofanov, Artem Paliy
 * Company: CodeTiburon
 * Last Edited: 23.12.2013
 */
namespace Zf2Acl\Mvc\View\Http;

use \Zend\Authentication\AuthenticationService;
use \Zend\Mvc\MvcEvent;

class GuestRedirectUserSetHttpCode extends AccessDeniedStrategy
{

    /**
     * Create and return a statusCode view model or redirect
     *
     * @param  MvcEvent $e
     *
     * @return void
     */
    public function prepareDeniedViewModel(MvcEvent $e)
    {
        $serviceManager = $e->getApplication()->getServiceManager();
        /** @var \Zf2Acl\Permissions\Acl\Acl $acl */
        $acl = $serviceManager->get('Acl');
        $routeMatch = $e->getRouteMatch();

        if ($routeMatch instanceof \Zend\Mvc\Router\RouteMatch &&
            !$acl->isAllowedRouteMatch($routeMatch) &&
            isset($this->config['accessDeniedStrategy'])) {
            $thisRoute = $routeMatch->getMatchedRouteName();
            $redirectRoutes = isset($this->config['accessDeniedStrategy']['redirects']) ?
                $this->config['accessDeniedStrategy']['redirects'] : null;

            if ($redirectRoutes) {
                $rout = '';
                if ((new AuthenticationService())->hasIdentity()) {
                    $redirectRoutes = $redirectRoutes['hasIdentity'];
                } else {
                    $redirectRoutes = $redirectRoutes['hasntIdentity'];
                }

                foreach ($redirectRoutes as $key => $val) {

                    if (in_array($thisRoute, $val) || in_array('all', $val)) {
                        $rout = $key;
                    }
                }
                if ($rout) {
                    $request = $e->getRequest();
                    $router = $e->getRouter();

                    $url = $router->assemble(array(), array('name' => $rout));
                    if (!$request->isXmlHttpRequest()) {
                        $response = $e->getResponse();
                        $response->setHeaders($response->getHeaders()->addHeaderLine('Location', $url));
                        $response->setStatusCode(200);
                        $response->sendHeaders();
                    } else {
                        echo json_encode(array('status' => true, 'redirectUrl' => $url, 'needParse' => true));
                    }
                    exit();
                }
            }
        }

        $vars = $e->getResult();
        if ($vars instanceof Response) {
            // Already have a response as the result
            return;
        }

        $response = $e->getResponse();
        if ($response->getStatusCode() != '403') {
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
