<?php

/*
 * This file is part of the 2amigos/yii2-usuario project.
 *
 * (c) 2amigOS! <http://2amigos.us/>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Da\User;

use Da\User\Component\AuthDbManagerComponent;
use Da\User\Contracts\AuthManagerInterface;
use Da\User\Helper\ClassMapHelper;
use Da\User\Model\User;
use Da\User\Validator\TimeZoneValidator;
use Yii;
use yii\authclient\Collection;
use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\base\Exception;
use yii\console\Application as ConsoleApplication;
use yii\i18n\PhpMessageSource;
use yii\web\Application as WebApplication;

/**
 * Bootstrap class of the yii2-usuario extension. Configures container services, initializes translations,
 * builds class map, and does the other setup actions participating in the application bootstrap process.
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * {@inheritdoc}
     */
    public function bootstrap($app)
    {
        if ($app->hasModule('user') && $app->getModule('user') instanceof Module) {
            $map = $this->buildClassMap($app->getModule('user')->classMap);
            $this->initContainer($app, $map);
            $this->initTranslations($app);
            $this->initMailServiceConfiguration($app, $app->getModule('user'));

            if ($app instanceof WebApplication) {
                $this->initControllerNamespace($app);
                $this->initUrlRoutes($app);
                $this->initAuthCollection($app);
                $this->initAuthManager($app);
            } else {
                /* @var $app ConsoleApplication */
                $this->initConsoleCommands($app);
                $this->initAuthManager($app);
            }
        }
    }

    /**
     * Initialize container with module classes.
     *
     * @param \yii\base\Application $app
     * @param array $map the previously built class map list
     */
    protected function initContainer($app, $map)
    {
        $di = Yii::$container;
        try {
            // events
            $di->set(Event\FormEvent::class);
            $di->set(Event\ProfileEvent::class);
            $di->set(Event\ResetPasswordEvent::class);
            $di->set(Event\SocialNetworkAuthEvent::class);
            $di->set(Event\SocialNetworkConnectEvent::class);
            $di->set(Event\UserEvent::class);

            // forms
            $di->set(Form\LoginForm::class);
            $di->set(Form\RecoveryForm::class);
            $di->set(Form\RegistrationForm::class);
            $di->set(Form\ResendForm::class);
            $di->set(Form\SettingsForm::class);

            // helpers
            $di->set(Helper\AuthHelper::class);
            $di->set(Helper\GravatarHelper::class);
            $di->set(Helper\SecurityHelper::class);
            $di->set(Helper\TimezoneHelper::class);

            // services
            $di->set(Service\AccountConfirmationService::class);
            $di->set(Service\EmailChangeService::class);
            $di->set(Service\PasswordRecoveryService::class);
            $di->set(Service\ResendConfirmationService::class);
            $di->set(Service\ResetPasswordService::class);
            $di->set(Service\SocialNetworkAccountConnectService::class);
            $di->set(Service\SocialNetworkAuthenticateService::class);
            $di->set(Service\UserBlockService::class);
            $di->set(Service\UserCreateService::class);
            $di->set(Service\UserRegisterService::class);
            $di->set(Service\UserConfirmationService::class);
            $di->set(Service\AuthItemEditionService::class);
            $di->set(Service\UpdateAuthAssignmentsService::class);

            // email change strategy
            $di->set(Strategy\DefaultEmailChangeStrategy::class);
            $di->set(Strategy\InsecureEmailChangeStrategy::class);
            $di->set(Strategy\SecureEmailChangeStrategy::class);

            // validators
            $di->set(Validator\AjaxRequestModelValidator::class);
            $di->set(TimeZoneValidator::class);

            // class map models + query classes
            $modelClassMap = [];
            foreach ($map as $class => $definition) {
                $di->set($class, $definition);
                $model = is_array($definition) ? $definition['class'] : $definition;
                $name = (substr($class, strrpos($class, '\\') + 1));
                $modelClassMap[$class] = $model;
                if (in_array($name, ['User', 'Profile', 'Token', 'SocialNetworkAccount'])) {
                    $di->set(
                        "Da\\User\\Query\\{$name}Query",
                        function () use ($model) {
                            return $model::find();
                        }
                    );
                }
            }
            $di->setSingleton(ClassMapHelper::class, ClassMapHelper::class, [$modelClassMap]);

            // search classes
            if (!$di->has(Search\UserSearch::class)) {
                $di->set(Search\UserSearch::class, [$di->get(Query\UserQuery::class)]);
            }
            if (!$di->has(Search\PermissionSearch::class)) {
                $di->set(Search\PermissionSearch::class);
            }
            if (!$di->has(Search\RoleSearch::class)) {
                $di->set(Search\RoleSearch::class);
            }

            if ($app instanceof WebApplication) {
                // override Yii
                $di->set(
                    'yii\web\User',
                    [
                        'enableAutoLogin' => true,
                        'loginUrl' => ['/user/security/login'],
                        'identityClass' => $di->get(ClassMapHelper::class)->get(User::class),
                    ]
                );
            }
        } catch (Exception $e) {
            die($e);
        }
    }

    /**
     * Registers module translation messages.
     *
     * @param Application $app
     */
    protected function initTranslations(Application $app)
    {
        if (!isset($app->get('i18n')->translations['usuario*'])) {
            $app->get('i18n')->translations['usuario*'] = [
                'class' => PhpMessageSource::class,
                'basePath' => __DIR__ . '/resources/i18n',
                'sourceLanguage' => 'en-US',
            ];
        }
    }

    /**
     * Ensures the auth manager is the one provided by the library.
     *
     * @param Application $app
     */
    protected function initAuthManager(Application $app)
    {
        if (!($app->getAuthManager() instanceof AuthManagerInterface)) {
            $app->set(
                'authManager',
                [
                    'class' => AuthDbManagerComponent::class,
                ]
            );
        }
    }

    /**
     * Initializes web url routes (rules in Yii2).
     *
     * @param WebApplication $app
     */
    protected function initUrlRoutes(WebApplication $app)
    {
        /** @var $module Module */
        $module = $app->getModule('user');
        $config = [
            'class' => 'yii\web\GroupUrlRule',
            'prefix' => $module->prefix,
            'rules' => $module->routes,
        ];

        if ($module->prefix !== 'user') {
            $config['routePrefix'] = 'user';
        }

        $rule = Yii::createObject($config);
        $app->getUrlManager()->addRules([$rule], false);
    }

    /**
     * Ensures required mail parameters needed for the mail service.
     *
     * @param Application $app
     * @param Module|\yii\base\Module $module
     */
    protected function initMailServiceConfiguration(Application $app, Module $module)
    {
        $defaults = [
            'fromEmail' => 'no-reply@example.com',
            'welcomeMailSubject' => Yii::t('usuario', 'Welcome to {0}', $app->name),
            'confirmationMailSubject' => Yii::t('usuario', 'Confirm account on {0}', $app->name),
            'reconfirmationMailSubject' => Yii::t('usuario', 'Confirm email change on {0}', $app->name),
            'recoveryMailSubject' => Yii::t('usuario', 'Complete password reset on {0}', $app->name),
        ];

        $module->mailParams = array_merge($defaults, $module->mailParams);
    }

    /**
     * Ensures the authCollection component is configured.
     *
     * @param WebApplication $app
     */
    protected function initAuthCollection(WebApplication $app)
    {
        if (!$app->has('authClientCollection')) {
            $app->set('authClientCollection', Collection::class);
        }
    }

    /**
     * Registers console commands to main app.
     *
     * @param ConsoleApplication $app
     */
    protected function initConsoleCommands(ConsoleApplication $app)
    {
        $app->getModule('user')->controllerNamespace = 'Da\User\Command';
    }

    /**
     * Registers controllers.
     *
     * @param WebApplication $app
     */
    protected function initControllerNamespace(WebApplication $app)
    {
        $app->getModule('user')->controllerNamespace = 'Da\User\Controller';
        $app->getModule('user')->setViewPath('@Da/User/resources/views');
    }

    /**
     * Builds class map according to user configuration.
     *
     * @param array $userClassMap user configuration on the module
     *
     * @return array
     */
    protected function buildClassMap(array $userClassMap)
    {
        $map = [];

        $defaults = [
            // --- models
            'User' => 'Da\User\Model\User',
            'SocialNetworkAccount' => 'Da\User\Model\SocialNetworkAccount',
            'Profile' => 'Da\User\Model\Profile',
            'Token' => 'Da\User\Model\Token',
            'Assignment' => 'Da\User\Model\Assignment',
            'Permission' => 'Da\User\Model\Permission',
            'Role' => 'Da\User\Model\Role',
            // --- search
            'UserSearch' => 'Da\User\Search\UserSearch',
            'PermissionSearch' => 'Da\User\Search\PermissionSearch',
            'RoleSearch' => 'Da\User\Search\RoleSearch',
            // --- forms
            'RegistrationForm' => 'Da\User\Form\RegistrationForm',
            'ResendForm' => 'Da\User\Form\ResendForm',
            'LoginForm' => 'Da\User\Form\LoginForm',
            'SettingsForm' => 'Da\User\Form\SettingsForm',
            'RecoveryForm' => 'Da\User\Form\RecoveryForm',
        ];

        $routes = [
            'Da\User\Model' => [
                'User',
                'SocialNetworkAccount',
                'Profile',
                'Token',
                'Assignment',
                'Permission',
                'Role',
            ],
            'Da\User\Search' => [
                'UserSearch',
                'PermissionSearch',
                'RoleSearch',
            ],
            'Da\UserForm' => [
                'RegistrationForm',
                'ResendForm',
                'LoginForm',
                'SettingsForm',
                'RecoveryForm',
            ],
        ];

        $mapping = array_merge($defaults, $userClassMap);

        foreach ($mapping as $name => $definition) {
            $map[$this->getRoute($routes, $name) . "\\$name"] = $definition;
        }

        return $map;
    }

    /**
     * Returns the parent class name route of a short class name.
     *
     * @param array $routes class name routes
     * @param string $name
     *
     * @return int|string
     *
     * @throws Exception
     */
    protected function getRoute(array $routes, $name)
    {
        foreach ($routes as $route => $names) {
            if (in_array($name, $names)) {
                return $route;
            }
        }
        throw new Exception("Unknown configuration class name '{$name}'");
    }
}