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

use Symfony\Component\HttpFoundation\Request;

/**
 * Description of CiHelperService
 *
 * @author nercury
 */
class CiHelperService {

    /**
     * @var boolean
     */
    protected $detectControllers;

    /**
     * @var \Monolog\Logger
     */
    protected $logger;

    /**
     *
     * @var \Symfony\Component\HttpKernel\Kernel
     */
    protected $kernel;

    /**
     *
     * @var \Symfony\Component\EventDispatcher\EventDispatcher
     */
    protected $event_dispatcher;
    protected $app_path = false;
    protected $system_path = false;

    public function __construct($detectControllers, $applicationPath, $systemPath, $logger, $kernel, $event_dispatcher) {
        $this->detectControllers = $detectControllers;
        $this->kernel = $kernel;
        $this->logger = $logger;
        $this->event_dispatcher = $event_dispatcher;
        $this->app_path = realpath($applicationPath);
        $this->system_path = realpath($systemPath);
    }

    protected function isConfigValid() {
        return $this->app_path !== false && $this->system_path !== false;
    }

    function getRelativePath($from, $to)
    {
        $from = explode('/', $from);
        $to = explode('/', $to);
        $relPath = $to;

        foreach ($from as $depth => $dir) {
            // find first non-matching dir
            if ($dir === $to[$depth]) {
                // ignore this directory
                array_shift($relPath);
            } else {
                // get number of remaining dirs to $from
                $remaining = count($from) - $depth;
                if ($remaining > 1) {
                    // add traversals up to first matching dir
                    $padLength = (count($relPath) + $remaining - 1) * -1;
                    $relPath = array_pad($relPath, $padLength, '..');
                    break;
                } else {
                    $relPath[0] = $relPath[0];
                }
            }
        }
        return implode('/', $relPath);
    }

    private $paths_initalized = false;

    /**
     * Initialize code igniter system paths and defines
     *
     * @param Request $request
     * @throws Exception
     */
    public function setCiPaths(Request $request = null) {
        if (!$this->isConfigValid()) {
            throw new \Exception('Code Igniter configuration is not valid.');
        }

        if ($this->paths_initalized === false) {
            $script_file = $request !== null
                ? '.' . $request->getBasePath() . $request->getScriptName()
                : __FILE__;

            $root_path = realpath($this->kernel->getRootDir().'/..');
            if ($root_path === false) {
                throw new \LogicException('Nercury CI bundle was expecting to find kernel root dir in /app directory.');
            }

            $system_path = $this->getRelativePath($root_path, $this->getSystemPath()).'/';
            $application_folder = $this->getRelativePath($root_path, $this->getAppPath());

            if ($script_file === __FILE__) {
                $script_file = $root_path . '/app.php';
                $system_path = realpath($root_path.'/'.$system_path).'/';
                $application_folder = realpath($root_path.'/'.$application_folder);
            }

            $environment = $this->kernel->getEnvironment();
            $environmentMap = array('dev' => 'development', 'test' => 'testing', 'prod' => 'production');
            if(array_key_exists($environment, $environmentMap)) {
                $environment = $environmentMap[$environment];
            }
            define('ENVIRONMENT', $environment);

            /*
            * -------------------------------------------------------------------
            *  Now that we know the path, set the main path constants
            * -------------------------------------------------------------------
            */
            // The name of THIS file
            define('SELF', pathinfo($script_file, PATHINFO_BASENAME));

            // The PHP file extension
            // this global constant is deprecated.
            define('EXT', '.php');

            // Path to the system folder
            define('BASEPATH', str_replace("\\", "/", $system_path));

            // Path to the front controller (this file)
            define('FCPATH', str_replace(SELF, '', __FILE__));

            // Name of the "system folder"
            define('SYSDIR', trim(strrchr(trim(BASEPATH, '/'), '/'), '/'));


            // The path to the "application" folder
            if (is_dir($application_folder)) {
                define('APPPATH', $application_folder . '/');
            } else {
                if (!is_dir(BASEPATH . $application_folder . '/')) {
                    exit("Your application folder path does not appear to be set correctly. Please open the following file and correct this: " . SELF);
                }

                define('APPPATH', BASEPATH . $application_folder . '/');
            }

            $this->paths_initalized = true;
        }
    }

    public function setBaseControllerOverrideClass($className) {
        $this->override_controller_class = $className;
    }

    private $override_controller_class = false;

    private static $ci_loaded = false;

    /**
     *
     * @throws Exception
     */
    public function getInstance($useFakeController = true) {
        $this->unsetNoticeErrorLevel();

        if (function_exists('get_instance')) {
            self::$ci_loaded = true;
        }

        if (!self::$ci_loaded) {
            self::$ci_loaded = true;

            if ($this->kernel->getContainer()->isScopeActive('request')) {
                $this->setCiPaths($this->kernel->getContainer()->get('request'));
            } else {
                $this->setCiPaths();
            }

            require_once __DIR__.'/ci_bootstrap.php';
            \ci_bootstrap($this->kernel, $this->override_controller_class, $useFakeController); // load without calling code igniter method but initiating CI class
        }

        return \get_instance();
    }

    /**
     * Return response from CI
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws Exception
     */
    public function getResponse(Request $request) {
        if (self::$ci_loaded)
            throw new \Exception('Can not create response for CodeIgniter controller, because another controller was already loaded.');

        self::$ci_loaded = true;

        $this->unsetNoticeErrorLevel();
        $this->setCiPaths($request);

        require_once __DIR__.'/ci_bootstrap.php';

        try {
            ob_start();

            /*
             * --------------------------------------------------------------------
             * LOAD THE BOOTSTRAP FILE
             * --------------------------------------------------------------------
             *
             * And away we go...
             *
             */
            \ci_bootstrap($this->kernel);

            $output = ob_get_clean();
        } catch (\Exception $e) {
            $output = ob_get_clean();

            $handler = new \Symfony\Component\HttpKernel\Debug\ExceptionHandler();

            return $handler->createResponse($e);
        }

        return new \Symfony\Component\HttpFoundation\Response($output);
    }

    /**
     * Returns CI APPPATH
     *
     * @return string Returns FALSE if path was not defined in config
     */
    public function getAppPath() {
        return $this->app_path;
    }

    /**
     * Returns CI system path
     *
     * @return string Returns FALSE if path was not defined in config
     */
    public function getSystemPath() {
        return $this->system_path;
    }

    public function unsetNoticeErrorLevel(){
        // code igniter likes notices
        $errorlevel = error_reporting();
        if ($errorlevel > 0) {
            error_reporting($errorlevel & ~ E_NOTICE);
        } elseif ($errorlevel < 0) {
            error_reporting(E_ALL & ~ E_NOTICE);
        }
    }

}

