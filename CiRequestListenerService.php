<?php

/*
 * Copyright 2012 Nerijus Arlauskas <nercury@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Nercury\CodeIgniterBundle;

use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpFoundation\Response;

/**
 * Listens to kernel request event and gets response from CI in case
 * a controller matches URL.
 *
 * @author nercury
 */
class CiRequestListenerService
{

    /**
     * @var \Symfony\Component\DependencyInjection\Container
     */
    private $container;

    private $appPath;

    private $detectControllers;

    public function __construct($container, $appPath, $detectControllers)
    {
        $this->container = $container;
        $this->appPath = $appPath;
        $this->detectControllers = $detectControllers;
    }

    /**
     * Get physical controller file name based on it's name
     *
     * @param string $controllerName
     * @return string
     */
    public function getControllerFile($controllerName)
    {
        return $this->appPath . '/controllers/' . $controllerName . '.php';
    }

    public function hasController($controller)
    {
        $controller_file = $this->getControllerFile($controller);

        if (file_exists($controller_file)) {
            return true;
        }
    }

    /**
     * This method listens to symfony request, and if it's url matches some controller
     * defined in CI path, it redirects request handling to CI.
     *
     * @param GetResponseEvent $event
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        if ($event->getRequestType() == \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST) {
            return;
        }

        $event = new CiActionResolveEvent($event->getRequest());
        if ($this->detectControllers !== false) {
            $this->container->get('event_dispatcher')->dispatch('nercury.ci_action_resolve', $event);
        }
        $actions = $event->getResolvedActions();

        foreach ($actions as $action) {
            if ($this->hasController($action['controller'])) {
                // handle everything over CI
                $event->getRequest()->setLocale($action['locale']);
                $event->setResponse($this->container->get('ci')->getResponse($event->getRequest()));
                $event->stopPropagation();
                break;
            }
        }
    }
}
