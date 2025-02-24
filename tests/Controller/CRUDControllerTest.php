<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Admin\FieldDescriptionCollection;
use Sonata\AdminBundle\Admin\Pool;
use Sonata\AdminBundle\Controller\CRUDController;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Sonata\AdminBundle\Exception\LockException;
use Sonata\AdminBundle\Exception\ModelManagerException;
use Sonata\AdminBundle\Export\Exporter as SonataExporter;
use Sonata\AdminBundle\Model\AuditManager;
use Sonata\AdminBundle\Model\AuditReaderInterface;
use Sonata\AdminBundle\Model\ModelManagerInterface;
use Sonata\AdminBundle\Security\Acl\Permission\AdminPermissionMap;
use Sonata\AdminBundle\Security\Handler\AclSecurityHandler;
use Sonata\AdminBundle\Templating\TemplateRegistryInterface;
use Sonata\AdminBundle\Tests\Fixtures\Admin\PostAdmin;
use Sonata\AdminBundle\Tests\Fixtures\Controller\BatchAdminController;
use Sonata\AdminBundle\Tests\Fixtures\Controller\PreCRUDController;
use Sonata\AdminBundle\Util\AdminObjectAclData;
use Sonata\AdminBundle\Util\AdminObjectAclManipulator;
use Sonata\Exporter\Exporter;
use Sonata\Exporter\Source\SourceIteratorInterface;
use Sonata\Exporter\Writer\JsonWriter;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Bundle\FrameworkBundle\Templating\DelegatingEngine;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Test for CRUDController.
 *
 * @author Andrej Hudec <pulzarraider@gmail.com>
 *
 * @group legacy
 */
class CRUDControllerTest extends TestCase
{
    /**
     * @var CRUDController
     */
    private $controller;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var AbstractAdmin
     */
    private $admin;

    /**
     * @var TemplateRegistryInterface
     */
    private $templateRegistry;

    /**
     * @var Pool
     */
    private $pool;

    /**
     * @var array
     */
    private $parameters;

    /**
     * @var Session
     */
    private $session;

    /**
     * @var AuditManager
     */
    private $auditManager;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var AdminObjectAclManipulator
     */
    private $adminObjectAclManipulator;

    /**
     * @var string
     */
    private $template;

    /**
     * @var array
     */
    private $protectedTestedMethods;

    /**
     * @var CsrfTokenManagerInterface
     */
    private $csrfProvider;

    /**
     * @var KernelInterface
     */
    private $kernel;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var FormBuilderInterface
     */
    private $formBuilder;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);

        $this->request = new Request();
        $this->pool = new Pool($this->container, 'title', 'logo.png');
        $this->pool->setAdminServiceIds(['foo.admin']);
        $this->request->attributes->set('_sonata_admin', 'foo.admin');
        $this->admin = $this->getMockBuilder(AbstractAdmin::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->parameters = [];
        $this->template = '';

        $this->formBuilder = $this->createMock(FormBuilderInterface::class);
        $this->admin->expects($this->any())
            ->method('getFormBuilder')
            ->willReturn($this->formBuilder);

        $this->templateRegistry = $this->prophesize(TemplateRegistryInterface::class);

        $templating = $this->getMockBuilder(DelegatingEngine::class)
            ->setConstructorArgs([$this->container, []])
            ->getMock();

        $templatingRenderReturnCallback = $this->returnCallback(function (
            $view,
            array $parameters = [],
            Response $response = null
        ) {
            $this->template = $view;

            if (null === $response) {
                $response = new Response();
            }

            $this->parameters = $parameters;

            return $response;
        });

        $templating->expects($this->any())
            ->method('render')
            ->will($templatingRenderReturnCallback);

        $this->session = new Session(new MockArraySessionStorage());

        $twig = $this->getMockBuilder(Environment::class)
            ->disableOriginalConstructor()
            ->getMock();

        $twig->expects($this->any())
            ->method('getRuntime')
            ->willReturn($this->createMock(FormRenderer::class));

        // NEXT_MAJOR : require sonata/exporter ^1.7 and remove conditional
        if (class_exists(Exporter::class)) {
            $exporter = new Exporter([new JsonWriter('/tmp/sonataadmin/export.json')]);
        } else {
            $exporter = $this->createMock(SonataExporter::class);

            $exporter->expects($this->any())
                ->method('getResponse')
                ->willReturn(new StreamedResponse());
        }

        $this->auditManager = $this->getMockBuilder(AuditManager::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->adminObjectAclManipulator = $this->getMockBuilder(AdminObjectAclManipulator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->csrfProvider = $this->getMockBuilder(CsrfTokenManagerInterface::class)
            ->getMock();

        $this->csrfProvider->expects($this->any())
            ->method('getToken')
            ->willReturnCallback(static function ($intention) {
                return new CsrfToken($intention, 'csrf-token-123_'.$intention);
            });

        $this->csrfProvider->expects($this->any())
            ->method('isTokenValid')
            ->willReturnCallback(static function (CsrfToken $token) {
                if ($token->getValue() === 'csrf-token-123_'.$token->getId()) {
                    return true;
                }

                return false;
            });

        $this->logger = $this->createMock(LoggerInterface::class);

        $requestStack = new RequestStack();
        $requestStack->push($this->request);

        $this->kernel = $this->createMock(KernelInterface::class);

        $this->container->expects($this->any())
            ->method('get')
            ->willReturnCallback(function ($id) use (
                $templating,
                $twig,
                $exporter,
                $requestStack
            ) {
                switch ($id) {
                    case 'sonata.admin.pool':
                        return $this->pool;
                    case 'request_stack':
                        return $requestStack;
                    case 'foo.admin':
                        return $this->admin;
                    case 'foo.admin.template_registry':
                        return $this->templateRegistry->reveal();
                    case 'templating':
                        return $templating;
                    case 'twig':
                        return $twig;
                    case 'session':
                        return $this->session;
                    case 'sonata.admin.exporter':
                        return $exporter;
                    case 'sonata.admin.audit.manager':
                        return $this->auditManager;
                    case 'sonata.admin.object.manipulator.acl.admin':
                        return $this->adminObjectAclManipulator;
                    case 'security.csrf.token_manager':
                        return $this->csrfProvider;
                    case 'logger':
                        return $this->logger;
                    case 'kernel':
                        return $this->kernel;
                    case 'translator':
                        return $this->translator;
                }
            });

        $this->container->expects($this->any())
            ->method('has')
            ->willReturnCallback(function ($id) {
                if ('security.csrf.token_manager' === $id && null !== $this->getCsrfProvider()) {
                    return true;
                }

                if ('logger' === $id) {
                    return true;
                }

                if ('session' === $id) {
                    return true;
                }

                if ('templating' === $id) {
                    return true;
                }

                if ('translator' === $id) {
                    return true;
                }

                return false;
            });

        $this->container->expects($this->any())
            ->method('getParameter')
            ->willReturnCallback(static function ($name) {
                switch ($name) {
                    case 'security.role_hierarchy.roles':
                       return ['ROLE_SUPER_ADMIN' => ['ROLE_USER', 'ROLE_SONATA_ADMIN', 'ROLE_ADMIN']];
                }
            });

        $this->templateRegistry->getTemplate('ajax')->willReturn('@SonataAdmin/ajax_layout.html.twig');
        $this->templateRegistry->getTemplate('layout')->willReturn('@SonataAdmin/standard_layout.html.twig');
        $this->templateRegistry->getTemplate('show')->willReturn('@SonataAdmin/CRUD/show.html.twig');
        $this->templateRegistry->getTemplate('show_compare')->willReturn('@SonataAdmin/CRUD/show_compare.html.twig');
        $this->templateRegistry->getTemplate('edit')->willReturn('@SonataAdmin/CRUD/edit.html.twig');
        $this->templateRegistry->getTemplate('dashboard')->willReturn('@SonataAdmin/Core/dashboard.html.twig');
        $this->templateRegistry->getTemplate('search')->willReturn('@SonataAdmin/Core/search.html.twig');
        $this->templateRegistry->getTemplate('list')->willReturn('@SonataAdmin/CRUD/list.html.twig');
        $this->templateRegistry->getTemplate('preview')->willReturn('@SonataAdmin/CRUD/preview.html.twig');
        $this->templateRegistry->getTemplate('history')->willReturn('@SonataAdmin/CRUD/history.html.twig');
        $this->templateRegistry->getTemplate('acl')->willReturn('@SonataAdmin/CRUD/acl.html.twig');
        $this->templateRegistry->getTemplate('delete')->willReturn('@SonataAdmin/CRUD/delete.html.twig');
        $this->templateRegistry->getTemplate('batch')->willReturn('@SonataAdmin/CRUD/list__batch.html.twig');
        $this->templateRegistry->getTemplate('batch_confirmation')->willReturn('@SonataAdmin/CRUD/batch_confirmation.html.twig');

        // NEXT_MAJOR: Remove this call
        $this->admin->method('getTemplate')->willReturnMap([
            ['ajax', '@SonataAdmin/ajax_layout.html.twig'],
            ['layout', '@SonataAdmin/standard_layout.html.twig'],
            ['show', '@SonataAdmin/CRUD/show.html.twig'],
            ['show_compare', '@SonataAdmin/CRUD/show_compare.html.twig'],
            ['edit', '@SonataAdmin/CRUD/edit.html.twig'],
            ['dashboard', '@SonataAdmin/Core/dashboard.html.twig'],
            ['search', '@SonataAdmin/Core/search.html.twig'],
            ['list', '@SonataAdmin/CRUD/list.html.twig'],
            ['preview', '@SonataAdmin/CRUD/preview.html.twig'],
            ['history', '@SonataAdmin/CRUD/history.html.twig'],
            ['acl', '@SonataAdmin/CRUD/acl.html.twig'],
            ['delete', '@SonataAdmin/CRUD/delete.html.twig'],
            ['batch', '@SonataAdmin/CRUD/list__batch.html.twig'],
            ['batch_confirmation', '@SonataAdmin/CRUD/batch_confirmation.html.twig'],
        ]);

        $this->admin->expects($this->any())
            ->method('getIdParameter')
            ->willReturn('id');

        $this->admin->expects($this->any())
            ->method('getAccessMapping')
            ->willReturn([]);

        $this->admin->expects($this->any())
            ->method('generateUrl')
            ->willReturnCallback(

                    static function ($name, array $parameters = [], $absolute = false) {
                        $result = $name;
                        if (!empty($parameters)) {
                            $result .= '?'.http_build_query($parameters);
                        }

                        return $result;
                    }

            );

        $this->admin->expects($this->any())
            ->method('generateObjectUrl')
            ->willReturnCallback(

                    static function ($name, $object, array $parameters = [], $absolute = false) {
                        $result = \get_class($object).'_'.$name;
                        if (!empty($parameters)) {
                            $result .= '?'.http_build_query($parameters);
                        }

                        return $result;
                    }

            );

        $this->admin->expects($this->any())
            ->method('getCode')
            ->willReturn('foo.admin');

        $this->controller = new CRUDController();
        $this->controller->setContainer($this->container);

        // Make some methods public to test them
        $testedMethods = [
            'renderJson',
            'isXmlHttpRequest',
            'configure',
            'getBaseTemplate',
            'redirectTo',
            'addFlash',
        ];
        foreach ($testedMethods as $testedMethod) {
            // NEXT_MAJOR: Remove this check and only use CRUDController
            if (method_exists(CRUDController::class, $testedMethod)) {
                $method = new \ReflectionMethod(CRUDController::class, $testedMethod);
            } else {
                $method = new \ReflectionMethod(Controller::class, $testedMethod);
            }

            $method->setAccessible(true);
            $this->protectedTestedMethods[$testedMethod] = $method;
        }
    }

    public function testRenderJson1(): void
    {
        $data = ['example' => '123', 'foo' => 'bar'];

        $this->request->headers->set('Content-Type', 'application/x-www-form-urlencoded');
        $response = $this->protectedTestedMethods['renderJson']->invoke($this->controller, $data, 200, [], $this->request);

        $this->assertSame($response->headers->get('Content-Type'), 'application/json');
        $this->assertSame(json_encode($data), $response->getContent());
    }

    public function testRenderJson2(): void
    {
        $data = ['example' => '123', 'foo' => 'bar'];

        $this->request->headers->set('Content-Type', 'multipart/form-data');
        $response = $this->protectedTestedMethods['renderJson']->invoke($this->controller, $data, 200, [], $this->request);

        $this->assertSame($response->headers->get('Content-Type'), 'application/json');
        $this->assertSame(json_encode($data), $response->getContent());
    }

    public function testRenderJsonAjax(): void
    {
        $data = ['example' => '123', 'foo' => 'bar'];

        $this->request->attributes->set('_xml_http_request', true);
        $this->request->headers->set('Content-Type', 'multipart/form-data');
        $response = $this->protectedTestedMethods['renderJson']->invoke($this->controller, $data, 200, [], $this->request);

        $this->assertSame($response->headers->get('Content-Type'), 'application/json');
        $this->assertSame(json_encode($data), $response->getContent());
    }

    public function testIsXmlHttpRequest(): void
    {
        $this->assertFalse($this->protectedTestedMethods['isXmlHttpRequest']->invoke($this->controller, $this->request));

        $this->request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $this->assertTrue($this->protectedTestedMethods['isXmlHttpRequest']->invoke($this->controller, $this->request));

        $this->request->headers->remove('X-Requested-With');
        $this->assertFalse($this->protectedTestedMethods['isXmlHttpRequest']->invoke($this->controller, $this->request));

        $this->request->attributes->set('_xml_http_request', true);
        $this->assertTrue($this->protectedTestedMethods['isXmlHttpRequest']->invoke($this->controller, $this->request));
    }

    public function testConfigure(): void
    {
        $uniqueId = '';

        $this->admin->expects($this->once())
            ->method('setUniqid')
            ->willReturnCallback(static function ($uniqid) use (&$uniqueId): void {
                $uniqueId = $uniqid;
            });

        $this->request->query->set('uniqid', 123456);
        $this->protectedTestedMethods['configure']->invoke($this->controller);

        $this->assertSame(123456, $uniqueId);
        $this->assertAttributeSame($this->admin, 'admin', $this->controller);
    }

    public function testConfigureChild(): void
    {
        $uniqueId = '';

        $this->admin->expects($this->once())
            ->method('setUniqid')
            ->willReturnCallback(static function ($uniqid) use (&$uniqueId): void {
                $uniqueId = $uniqid;
            });

        $this->admin->expects($this->once())
            ->method('isChild')
            ->willReturn(true);

        $adminParent = $this->getMockBuilder(AbstractAdmin::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->admin->expects($this->once())
            ->method('getParent')
            ->willReturn($adminParent);

        $this->request->query->set('uniqid', 123456);
        $this->protectedTestedMethods['configure']->invoke($this->controller);

        $this->assertSame(123456, $uniqueId);
        $this->assertAttributeInstanceOf(\get_class($adminParent), 'admin', $this->controller);
    }

    public function testConfigureWithException(): void
    {
        $this->expectException(
            \RuntimeException::class,
            'There is no `_sonata_admin` defined for the controller `Sonata\AdminBundle\Controller\CRUDController`'
        );

        $this->request->attributes->remove('_sonata_admin');
        $this->protectedTestedMethods['configure']->invoke($this->controller);
    }

    public function testConfigureWithException2(): void
    {
        $this->expectException(
            \InvalidArgumentException::class,
            'Found service "nonexistent.admin" is not a valid admin service'
        );

        $this->pool->setAdminServiceIds(['nonexistent.admin']);
        $this->request->attributes->set('_sonata_admin', 'nonexistent.admin');
        $this->protectedTestedMethods['configure']->invoke($this->controller);
    }

    public function testGetBaseTemplate(): void
    {
        $this->assertSame(
            '@SonataAdmin/standard_layout.html.twig',
            $this->protectedTestedMethods['getBaseTemplate']->invoke($this->controller, $this->request)
        );

        $this->request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $this->assertSame(
            '@SonataAdmin/ajax_layout.html.twig',
            $this->protectedTestedMethods['getBaseTemplate']->invoke($this->controller, $this->request)
        );

        $this->request->headers->remove('X-Requested-With');
        $this->assertSame(
            '@SonataAdmin/standard_layout.html.twig',
            $this->protectedTestedMethods['getBaseTemplate']->invoke($this->controller, $this->request)
        );

        $this->request->attributes->set('_xml_http_request', true);
        $this->assertSame(
            '@SonataAdmin/ajax_layout.html.twig',
            $this->protectedTestedMethods['getBaseTemplate']->invoke($this->controller, $this->request)
        );
    }

    public function testRender(): void
    {
        $this->parameters = [];
        $this->assertInstanceOf(
            Response::class,
            $this->controller->renderWithExtraParams('@FooAdmin/foo.html.twig', [], null)
        );
        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);
        $this->assertSame('@FooAdmin/foo.html.twig', $this->template);
    }

    public function testRenderWithResponse(): void
    {
        $this->parameters = [];
        $response = $response = new Response();
        $response->headers->set('X-foo', 'bar');
        $responseResult = $this->controller->renderWithExtraParams('@FooAdmin/foo.html.twig', [], $response);

        $this->assertSame($response, $responseResult);
        $this->assertSame('bar', $responseResult->headers->get('X-foo'));
        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);
        $this->assertSame('@FooAdmin/foo.html.twig', $this->template);
    }

    public function testRenderCustomParams(): void
    {
        $this->parameters = [];
        $this->assertInstanceOf(
            Response::class,
            $this->controller->renderWithExtraParams(
                '@FooAdmin/foo.html.twig',
                ['foo' => 'bar'],
                null
            )
        );
        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);
        $this->assertSame('bar', $this->parameters['foo']);
        $this->assertSame('@FooAdmin/foo.html.twig', $this->template);
    }

    public function testRenderAjax(): void
    {
        $this->parameters = [];
        $this->request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $this->assertInstanceOf(
            Response::class,
            $this->controller->renderWithExtraParams(
                '@FooAdmin/foo.html.twig',
                ['foo' => 'bar'],
                null
            )
        );
        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/ajax_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);
        $this->assertSame('bar', $this->parameters['foo']);
        $this->assertSame('@FooAdmin/foo.html.twig', $this->template);
    }

    public function testListActionAccessDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('list'))
            ->will($this->throwException(new AccessDeniedException()));

        $this->controller->listAction($this->request);
    }

    public function testPreList(): void
    {
        $this->admin->expects($this->any())
            ->method('hasRoute')
            ->with($this->equalTo('list'))
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('list'))
            ->willReturn(true);

        $controller = new PreCRUDController();
        $controller->setContainer($this->container);

        $response = $controller->listAction($this->request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('preList called', $response->getContent());
    }

    public function testListAction(): void
    {
        $datagrid = $this->createMock(DatagridInterface::class);

        $this->admin->expects($this->any())
            ->method('hasRoute')
            ->with($this->equalTo('list'))
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('list'))
            ->willReturn(true);

        $form = $this->getMockBuilder(Form::class)
            ->disableOriginalConstructor()
            ->getMock();

        $form->expects($this->once())
            ->method('createView')
            ->willReturn($this->createMock(FormView::class));

        $this->admin->expects($this->once())
            ->method('getDatagrid')
            ->willReturn($datagrid);

        $datagrid->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $this->parameters = [];
        $this->assertInstanceOf(Response::class, $this->controller->listAction($this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('list', $this->parameters['action']);
        $this->assertInstanceOf(FormView::class, $this->parameters['form']);
        $this->assertInstanceOf(DatagridInterface::class, $this->parameters['datagrid']);
        $this->assertSame('csrf-token-123_sonata.batch', $this->parameters['csrf_token']);
        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/list.html.twig', $this->template);
    }

    public function testBatchActionDeleteAccessDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('batchDelete'))
            ->will($this->throwException(new AccessDeniedException()));

        $this->controller->batchActionDelete($this->createMock(ProxyQueryInterface::class));
    }

    public function testBatchActionDelete(): void
    {
        $modelManager = $this->createMock(ModelManagerInterface::class);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('batchDelete'))
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('getModelManager')
            ->willReturn($modelManager);

        $this->admin->expects($this->once())
            ->method('getFilterParameters')
            ->willReturn(['foo' => 'bar']);

        $this->expectTranslate('flash_batch_delete_success', [], 'SonataAdminBundle');

        $result = $this->controller->batchActionDelete($this->createMock(ProxyQueryInterface::class));

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(['flash_batch_delete_success'], $this->session->getFlashBag()->get('sonata_flash_success'));
        $this->assertSame('list?filter%5Bfoo%5D=bar', $result->getTargetUrl());
    }

    public function testBatchActionDeleteWithModelManagerException(): void
    {
        $modelManager = $this->createMock(ModelManagerInterface::class);
        $this->assertLoggerLogsModelManagerException($modelManager, 'batchDelete');

        $this->admin->expects($this->once())
            ->method('getModelManager')
            ->willReturn($modelManager);

        $this->admin->expects($this->once())
            ->method('getFilterParameters')
            ->willReturn(['foo' => 'bar']);

        $this->expectTranslate('flash_batch_delete_error', [], 'SonataAdminBundle');

        $result = $this->controller->batchActionDelete($this->createMock(ProxyQueryInterface::class));

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(['flash_batch_delete_error'], $this->session->getFlashBag()->get('sonata_flash_error'));
        $this->assertSame('list?filter%5Bfoo%5D=bar', $result->getTargetUrl());
    }

    public function testBatchActionDeleteWithModelManagerExceptionInDebugMode(): void
    {
        $modelManager = $this->createMock(ModelManagerInterface::class);
        $this->expectException(ModelManagerException::class);

        $modelManager->expects($this->once())
            ->method('batchDelete')
            ->willReturnCallback(static function (): void {
                throw new ModelManagerException();
            });

        $this->admin->expects($this->once())
            ->method('getModelManager')
            ->willReturn($modelManager);

        $this->kernel->expects($this->once())
            ->method('isDebug')
            ->willReturn(true);

        $this->controller->batchActionDelete($this->createMock(ProxyQueryInterface::class));
    }

    public function testShowActionNotFoundException(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn(false);

        $this->controller->showAction(null, $this->request);
    }

    public function testShowActionAccessDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn(new \stdClass());

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('show'))
            ->will($this->throwException(new AccessDeniedException()));

        $this->controller->showAction(null, $this->request);
    }

    /**
     * @group legacy
     * @expectedDeprecation Calling this method without implementing "configureShowFields" is not supported since 3.40.0 and will no longer be possible in 4.0
     */
    public function testShowActionDeprecation(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('show'))
            ->willReturn(true);

        $show = $this->createMock(FieldDescriptionCollection::class);

        $this->admin->expects($this->once())
            ->method('getShow')
            ->willReturn($show);

        $show->expects($this->once())
            ->method('getElements')
            ->willReturn([]);

        $show->expects($this->once())
            ->method('count')
            ->willReturn(0);

        $this->controller->showAction(null, $this->request);
    }

    public function testPreShow(): void
    {
        $object = new \stdClass();
        $object->foo = 123456;

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('show'))
            ->willReturn(true);

        $controller = new PreCRUDController();
        $controller->setContainer($this->container);

        $response = $controller->showAction(null, $this->request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('preShow called: 123456', $response->getContent());
    }

    public function testShowAction(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('show'))
            ->willReturn(true);

        $show = $this->createMock(FieldDescriptionCollection::class);

        $this->admin->expects($this->once())
            ->method('getShow')
            ->willReturn($show);

        $show->expects($this->once())
            ->method('getElements')
            ->willReturn(['field' => 'fielddata']);

        $this->assertInstanceOf(Response::class, $this->controller->showAction(null, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('show', $this->parameters['action']);
        $this->assertInstanceOf(FieldDescriptionCollection::class, $this->parameters['elements']);
        $this->assertSame($object, $this->parameters['object']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/show.html.twig', $this->template);
    }

    /**
     * @dataProvider getRedirectToTests
     */
    public function testRedirectTo($expected, $route, $queryParams, $requestParams, $hasActiveSubclass): void
    {
        $this->admin->expects($this->any())
            ->method('hasActiveSubclass')
            ->willReturn($hasActiveSubclass);

        $object = new \stdClass();

        foreach ($queryParams as $key => $value) {
            $this->request->query->set($key, $value);
        }

        foreach ($requestParams as $key => $value) {
            $this->request->request->set($key, $value);
        }

        $this->admin->expects($this->any())
            ->method('hasRoute')
            ->with($this->equalTo($route))
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('hasAccess')
            ->with($this->equalTo($route))
            ->willReturn(true);

        $response = $this->protectedTestedMethods['redirectTo']->invoke($this->controller, $object, $this->request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame($expected, $response->getTargetUrl());
    }

    public function testRedirectToWithObject(): void
    {
        $this->admin->expects($this->any())
            ->method('hasActiveSubclass')
            ->willReturn(false);

        $object = new \stdClass();

        $this->admin->expects($this->at(0))
            ->method('hasRoute')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('hasAccess')
            ->with($this->equalTo('edit'), $object)
            ->willReturn(false);

        $response = $this->protectedTestedMethods['redirectTo']->invoke($this->controller, $object, $this->request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('list', $response->getTargetUrl());
    }

    public function getRedirectToTests()
    {
        return [
            ['stdClass_edit', 'edit', [], [], false],
            ['list', 'list', ['btn_update_and_list' => true], [], false],
            ['list', 'list', ['btn_create_and_list' => true], [], false],
            ['create', 'create', ['btn_create_and_create' => true], [], false],
            ['create?subclass=foo', 'create', ['btn_create_and_create' => true, 'subclass' => 'foo'], [], true],
            ['stdClass_edit?_tab=first_tab', 'edit', ['btn_update_and_edit' => true], ['_tab' => 'first_tab'], false],
        ];
    }

    public function testDeleteActionNotFoundException(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn(false);

        $this->controller->deleteAction(1, $this->request);
    }

    public function testDeleteActionAccessDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn(new \stdClass());

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->will($this->throwException(new AccessDeniedException()));

        $this->controller->deleteAction(1, $this->request);
    }

    public function testPreDelete(): void
    {
        $object = new \stdClass();
        $object->foo = 123456;

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $controller = new PreCRUDController();
        $controller->setContainer($this->container);

        $response = $controller->deleteAction(null, $this->request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('preDelete called: 123456', $response->getContent());
    }

    public function testDeleteAction(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $this->assertInstanceOf(Response::class, $this->controller->deleteAction(1, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('delete', $this->parameters['action']);
        $this->assertSame($object, $this->parameters['object']);
        $this->assertSame('csrf-token-123_sonata.delete', $this->parameters['csrf_token']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/delete.html.twig', $this->template);
    }

    /**
     * @group legacy
     * @expectedDeprecation Accessing a child that isn't connected to a given parent is deprecated since 3.34 and won't be allowed in 4.0.
     */
    public function testDeleteActionChildDeprecation(): void
    {
        $object = new \stdClass();
        $object->parent = 'test';

        $object2 = new \stdClass();

        $admin = $this->createMock(PostAdmin::class);

        $admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object2);

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('getParent')
            ->willReturn($admin);

        $this->admin->expects($this->exactly(2))
            ->method('getParentAssociationMapping')
            ->willReturn('parent');

        $this->controller->deleteAction(1, $this->request);
    }

    public function testDeleteActionNoParentMappings(): void
    {
        $object = new \stdClass();

        $admin = $this->createMock(PostAdmin::class);

        $admin->expects($this->never())
            ->method('getObject');

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('getParent')
            ->willReturn($admin);

        $this->admin->expects($this->once())
            ->method('getParentAssociationMapping')
            ->willReturn(false);

        $this->controller->deleteAction(1, $this->request);
    }

    public function testDeleteActionNoCsrfToken(): void
    {
        $this->csrfProvider = null;

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $this->assertInstanceOf(Response::class, $this->controller->deleteAction(1, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('delete', $this->parameters['action']);
        $this->assertSame($object, $this->parameters['object']);
        $this->assertFalse($this->parameters['csrf_token']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/delete.html.twig', $this->template);
    }

    public function testDeleteActionAjaxSuccess1(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $this->request->setMethod('DELETE');

        $this->request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.delete');

        $response = $this->controller->deleteAction(1, $this->request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(json_encode(['result' => 'ok']), $response->getContent());
        $this->assertSame([], $this->session->getFlashBag()->all());
    }

    public function testDeleteActionAjaxSuccess2(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $this->request->setMethod('POST');
        $this->request->request->set('_method', 'DELETE');
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.delete');

        $this->request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->deleteAction(1, $this->request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(json_encode(['result' => 'ok']), $response->getContent());
        $this->assertSame([], $this->session->getFlashBag()->all());
    }

    public function testDeleteActionAjaxError(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $this->assertLoggerLogsModelManagerException($this->admin, 'delete');

        $this->request->setMethod('DELETE');

        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.delete');
        $this->request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->deleteAction(1, $this->request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(json_encode(['result' => 'error']), $response->getContent());
        $this->assertSame([], $this->session->getFlashBag()->all());
    }

    public function testDeleteActionWithModelManagerExceptionInDebugMode(): void
    {
        $this->expectException(ModelManagerException::class);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('delete')
            ->willReturnCallback(static function (): void {
                throw new ModelManagerException();
            });

        $this->kernel->expects($this->once())
            ->method('isDebug')
            ->willReturn(true);

        $this->request->setMethod('DELETE');
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.delete');

        $this->controller->deleteAction(1, $this->request);
    }

    /**
     * @dataProvider getToStringValues
     */
    public function testDeleteActionSuccess1($expectedToStringValue, $toStringValue): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('toString')
            ->with($this->equalTo($object))
            ->willReturn($toStringValue);

        $this->expectTranslate('flash_delete_success', ['%name%' => $expectedToStringValue], 'SonataAdminBundle');

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $this->request->setMethod('DELETE');

        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.delete');

        $response = $this->controller->deleteAction(1, $this->request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(['flash_delete_success'], $this->session->getFlashBag()->get('sonata_flash_success'));
        $this->assertSame('list', $response->getTargetUrl());
    }

    /**
     * @dataProvider getToStringValues
     */
    public function testDeleteActionSuccess2($expectedToStringValue, $toStringValue): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('toString')
            ->with($this->equalTo($object))
            ->willReturn($toStringValue);

        $this->expectTranslate('flash_delete_success', ['%name%' => $expectedToStringValue], 'SonataAdminBundle');

        $this->request->setMethod('POST');
        $this->request->request->set('_method', 'DELETE');

        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.delete');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $response = $this->controller->deleteAction(1, $this->request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(['flash_delete_success'], $this->session->getFlashBag()->get('sonata_flash_success'));
        $this->assertSame('list', $response->getTargetUrl());
    }

    /**
     * @dataProvider getToStringValues
     */
    public function testDeleteActionSuccessNoCsrfTokenProvider($expectedToStringValue, $toStringValue): void
    {
        $this->csrfProvider = null;

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('toString')
            ->with($this->equalTo($object))
            ->willReturn($toStringValue);

        $this->expectTranslate('flash_delete_success', ['%name%' => $expectedToStringValue], 'SonataAdminBundle');

        $this->request->setMethod('POST');
        $this->request->request->set('_method', 'DELETE');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $response = $this->controller->deleteAction(1, $this->request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(['flash_delete_success'], $this->session->getFlashBag()->get('sonata_flash_success'));
        $this->assertSame('list', $response->getTargetUrl());
    }

    public function testDeleteActionWrongRequestMethod(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        //without POST request parameter "_method" should not be used as real REST method
        $this->request->query->set('_method', 'DELETE');

        $this->assertInstanceOf(Response::class, $this->controller->deleteAction(1, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('delete', $this->parameters['action']);
        $this->assertSame($object, $this->parameters['object']);
        $this->assertSame('csrf-token-123_sonata.delete', $this->parameters['csrf_token']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/delete.html.twig', $this->template);
    }

    /**
     * @dataProvider getToStringValues
     */
    public function testDeleteActionError($expectedToStringValue, $toStringValue): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('toString')
            ->with($this->equalTo($object))
            ->willReturn($toStringValue);

        $this->expectTranslate('flash_delete_error', ['%name%' => $expectedToStringValue], 'SonataAdminBundle');

        $this->assertLoggerLogsModelManagerException($this->admin, 'delete');

        $this->request->setMethod('DELETE');
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.delete');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $response = $this->controller->deleteAction(1, $this->request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(['flash_delete_error'], $this->session->getFlashBag()->get('sonata_flash_error'));
        $this->assertSame('list', $response->getTargetUrl());
    }

    public function testDeleteActionInvalidCsrfToken(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $this->request->setMethod('POST');
        $this->request->request->set('_method', 'DELETE');
        $this->request->request->set('_sonata_csrf_token', 'CSRF-INVALID');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        try {
            $this->controller->deleteAction(1, $this->request);
        } catch (HttpException $e) {
            $this->assertSame('The csrf token is not valid, CSRF attack?', $e->getMessage());
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function testDeleteActionWithDisabledCsrfProtection(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('delete'))
            ->willReturn(true);

        $this->request->setMethod('POST');
        $this->request->request->set('_method', 'DELETE');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(false);

        $this->admin->expects($this->once())
            ->method('toString')
            ->with($object)
            ->willReturn(\stdClass::class);

        $this->admin->expects($this->once())
            ->method('delete')
            ->with($object);

        $this->controller->deleteAction(1, $this->request);
    }

    public function testEditActionNotFoundException(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn(false);

        $this->controller->editAction(null, $this->request);
    }

    public function testEditActionRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn(new \stdClass());

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('all')
            ->willReturn([]);

        $this->controller->editAction(null, $this->request);
    }

    public function testEditActionAccessDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn(new \stdClass());

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('edit'))
            ->will($this->throwException(new AccessDeniedException()));

        $this->controller->editAction(null, $this->request);
    }

    public function testPreEdit(): void
    {
        $object = new \stdClass();
        $object->foo = 123456;

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $controller = new PreCRUDController();
        $controller->setContainer($this->container);

        $response = $controller->editAction(null, $this->request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('preEdit called: 123456', $response->getContent());
    }

    public function testEditAction(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $formView = $this->createMock(FormView::class);

        $form->expects($this->any())
            ->method('createView')
            ->willReturn($formView);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $this->assertInstanceOf(Response::class, $this->controller->editAction(null, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('edit', $this->parameters['action']);
        $this->assertInstanceOf(FormView::class, $this->parameters['form']);
        $this->assertSame($object, $this->parameters['object']);
        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/edit.html.twig', $this->template);
    }

    /**
     * @dataProvider getToStringValues
     */
    public function testEditActionSuccess($expectedToStringValue, $toStringValue): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('edit'));

        $this->admin->expects($this->once())
            ->method('hasRoute')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('hasAccess')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $form = $this->createMock(Form::class);

        $form->expects($this->once())
            ->method('getData')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $this->admin->expects($this->once())
            ->method('toString')
            ->with($this->equalTo($object))
            ->willReturn($toStringValue);

        $this->expectTranslate('flash_edit_success', ['%name%' => $expectedToStringValue], 'SonataAdminBundle');

        $this->request->setMethod('POST');

        $response = $this->controller->editAction(null, $this->request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(['flash_edit_success'], $this->session->getFlashBag()->get('sonata_flash_success'));
        $this->assertSame('stdClass_edit', $response->getTargetUrl());
    }

    /**
     * @dataProvider getToStringValues
     */
    public function testEditActionError($expectedToStringValue, $toStringValue): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(false);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $this->admin->expects($this->once())
            ->method('toString')
            ->with($this->equalTo($object))
            ->willReturn($toStringValue);

        $this->expectTranslate('flash_edit_error', ['%name%' => $expectedToStringValue], 'SonataAdminBundle');

        $this->request->setMethod('POST');

        $formView = $this->createMock(FormView::class);

        $form->expects($this->any())
            ->method('createView')
            ->willReturn($formView);

        $this->assertInstanceOf(Response::class, $this->controller->editAction(null, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('edit', $this->parameters['action']);
        $this->assertInstanceOf(FormView::class, $this->parameters['form']);
        $this->assertSame($object, $this->parameters['object']);

        $this->assertSame(['sonata_flash_error' => ['flash_edit_error']], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/edit.html.twig', $this->template);
    }

    public function testEditActionAjaxSuccess(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('getData')
            ->willReturn($object);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $this->admin
            ->method('getNormalizedIdentifier')
            ->with($this->equalTo($object))
            ->willReturn('foo_normalized');

        $this->admin->expects($this->once())
            ->method('toString')
            ->willReturn('foo');

        $this->request->setMethod('POST');
        $this->request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->editAction(null, $this->request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(json_encode(['result' => 'ok', 'objectId' => 'foo_normalized', 'objectName' => 'foo']), $response->getContent());
        $this->assertSame([], $this->session->getFlashBag()->all());
    }

    public function testEditActionAjaxError(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(false);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $formError = $this->createMock(FormError::class);
        $formError->expects($this->atLeastOnce())
            ->method('getMessage')
            ->willReturn('Form error message');

        $form->expects($this->once())
            ->method('getErrors')
            ->with(true)
            ->willReturn([$formError]);

        $this->request->setMethod('POST');
        $this->request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $this->request->headers->set('Accept', 'application/json');

        $this->assertInstanceOf(JsonResponse::class, $response = $this->controller->editAction(null, $this->request));
        $this->assertJsonStringEqualsJsonString('{"result":"error","errors":["Form error message"]}', $response->getContent());
    }

    /**
     * @legacy
     * @expectedDeprecation In next major version response will return 406 NOT ACCEPTABLE without `Accept: application/json`
     */
    public function testEditActionAjaxErrorWithoutAcceptApplicationJson(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(false);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $this->request->setMethod('POST');
        $this->request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $formView = $this->createMock(FormView::class);
        $form
            ->method('createView')
            ->willReturn($formView);

        $this->assertInstanceOf(Response::class, $response = $this->controller->editAction(null, $this->request));
        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/ajax_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);
        $this->assertSame('edit', $this->parameters['action']);
        $this->assertInstanceOf(FormView::class, $this->parameters['form']);
        $this->assertSame($object, $this->parameters['object']);
        $this->assertSame(['sonata_flash_error' => [0 => null]], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/edit.html.twig', $this->template);
    }

    /**
     * @dataProvider getToStringValues
     */
    public function testEditActionWithModelManagerException($expectedToStringValue, $toStringValue): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('getData')
            ->willReturn($object);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $this->admin->expects($this->once())
            ->method('toString')
            ->with($this->equalTo($object))
            ->willReturn($toStringValue);

        $this->expectTranslate('flash_edit_error', ['%name%' => $expectedToStringValue], 'SonataAdminBundle');

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);
        $this->request->setMethod('POST');

        $formView = $this->createMock(FormView::class);

        $form->expects($this->any())
            ->method('createView')
            ->willReturn($formView);

        $this->assertLoggerLogsModelManagerException($this->admin, 'update');
        $this->assertInstanceOf(Response::class, $this->controller->editAction(null, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('edit', $this->parameters['action']);
        $this->assertInstanceOf(FormView::class, $this->parameters['form']);
        $this->assertSame($object, $this->parameters['object']);

        $this->assertSame(['sonata_flash_error' => ['flash_edit_error']], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/edit.html.twig', $this->template);
    }

    public function testEditActionWithPreview(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $this->admin->expects($this->once())
            ->method('supportsPreviewMode')
            ->willReturn(true);

        $formView = $this->createMock(FormView::class);

        $form->expects($this->any())
            ->method('createView')
            ->willReturn($formView);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $this->request->setMethod('POST');
        $this->request->request->set('btn_preview', 'Preview');

        $this->assertInstanceOf(Response::class, $this->controller->editAction(null, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('edit', $this->parameters['action']);
        $this->assertInstanceOf(FormView::class, $this->parameters['form']);
        $this->assertSame($object, $this->parameters['object']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/preview.html.twig', $this->template);
    }

    public function testEditActionWithLockException(): void
    {
        $object = new \stdClass();
        $class = \get_class($object);

        $this->admin->expects($this->any())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('checkAccess')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn($class);

        $form = $this->createMock(Form::class);

        $form->expects($this->any())
            ->method('isValid')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('getData')
            ->willReturn($object);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $this->admin->expects($this->any())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->any())
            ->method('isSubmitted')
            ->willReturn(true);
        $this->request->setMethod('POST');

        $this->admin->expects($this->any())
            ->method('update')
            ->will($this->throwException(new LockException()));

        $this->admin->expects($this->any())
            ->method('toString')
            ->with($this->equalTo($object))
            ->willReturn($class);

        $formView = $this->createMock(FormView::class);

        $form->expects($this->any())
            ->method('createView')
            ->willReturn($formView);

        $this->expectTranslate('flash_lock_error', [
            '%name%' => $class,
            '%link_start%' => '<a href="stdClass_edit">',
            '%link_end%' => '</a>',
        ], 'SonataAdminBundle');

        $this->assertInstanceOf(Response::class, $this->controller->editAction(null, $this->request));
    }

    public function testCreateActionAccessDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('create'))
            ->will($this->throwException(new AccessDeniedException()));

        $this->controller->createAction($this->request);
    }

    public function testCreateActionRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('create'))
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $this->admin->expects($this->once())
            ->method('getNewInstance')
            ->willReturn(new \stdClass());

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('all')
            ->willReturn([]);

        $this->controller->createAction($this->request);
    }

    public function testPreCreate(): void
    {
        $object = new \stdClass();
        $object->foo = 123456;

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('create'))
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $this->admin->expects($this->once())
            ->method('getNewInstance')
            ->willReturn($object);

        $controller = new PreCRUDController();
        $controller->setContainer($this->container);

        $response = $controller->createAction($this->request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('preCreate called: 123456', $response->getContent());
    }

    public function testCreateAction(): void
    {
        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('create'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $this->admin->expects($this->once())
            ->method('getNewInstance')
            ->willReturn($object);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $formView = $this->createMock(FormView::class);

        $form->expects($this->any())
            ->method('createView')
            ->willReturn($formView);

        $this->assertInstanceOf(Response::class, $this->controller->createAction($this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('create', $this->parameters['action']);
        $this->assertInstanceOf(FormView::class, $this->parameters['form']);
        $this->assertSame($object, $this->parameters['object']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/edit.html.twig', $this->template);
    }

    /**
     * @dataProvider getToStringValues
     */
    public function testCreateActionSuccess($expectedToStringValue, $toStringValue): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->exactly(2))
            ->method('checkAccess')
            ->willReturnCallback(static function ($name, $objectIn = null) use ($object) {
                if ('edit' === $name) {
                    return true;
                }

                if ('create' !== $name) {
                    return false;
                }

                if (null === $objectIn) {
                    return true;
                }

                return $objectIn === $object;
            });

        $this->admin->expects($this->once())
            ->method('hasRoute')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('hasAccess')
            ->with($this->equalTo('edit'))
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('getNewInstance')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('create')
            ->willReturnArgument(0);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('getData')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('toString')
            ->with($this->equalTo($object))
            ->willReturn($toStringValue);

        $this->expectTranslate('flash_create_success', ['%name%' => $expectedToStringValue], 'SonataAdminBundle');

        $this->request->setMethod('POST');

        $response = $this->controller->createAction($this->request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(['flash_create_success'], $this->session->getFlashBag()->get('sonata_flash_success'));
        $this->assertSame('stdClass_edit', $response->getTargetUrl());
    }

    public function testCreateActionAccessDenied2(): void
    {
        $this->expectException(AccessDeniedException::class);

        $object = new \stdClass();

        $this->admin->expects($this->any())
            ->method('checkAccess')
            ->willReturnCallback(static function ($name, $object = null) {
                if ('create' !== $name) {
                    throw new AccessDeniedException();
                }
                if (null === $object) {
                    return true;
                }

                throw new AccessDeniedException();
            });

        $this->admin->expects($this->once())
            ->method('getNewInstance')
            ->willReturn($object);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('getData')
            ->willReturn($object);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $this->request->setMethod('POST');

        $this->controller->createAction($this->request);
    }

    /**
     * @dataProvider getToStringValues
     */
    public function testCreateActionError($expectedToStringValue, $toStringValue): void
    {
        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('create'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $this->admin->expects($this->once())
            ->method('getNewInstance')
            ->willReturn($object);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(false);

        $this->admin->expects($this->once())
            ->method('toString')
            ->with($this->equalTo($object))
            ->willReturn($toStringValue);

        $this->expectTranslate('flash_create_error', ['%name%' => $expectedToStringValue], 'SonataAdminBundle');

        $this->request->setMethod('POST');

        $formView = $this->createMock(FormView::class);

        $form->expects($this->any())
            ->method('createView')
            ->willReturn($formView);

        $this->assertInstanceOf(Response::class, $this->controller->createAction($this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('create', $this->parameters['action']);
        $this->assertInstanceOf(FormView::class, $this->parameters['form']);
        $this->assertSame($object, $this->parameters['object']);

        $this->assertSame(['sonata_flash_error' => ['flash_create_error']], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/edit.html.twig', $this->template);
    }

    /**
     * @dataProvider getToStringValues
     */
    public function testCreateActionWithModelManagerException($expectedToStringValue, $toStringValue): void
    {
        $this->admin->expects($this->exactly(2))
            ->method('checkAccess')
            ->with($this->equalTo('create'))
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getNewInstance')
            ->willReturn($object);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('toString')
            ->with($this->equalTo($object))
            ->willReturn($toStringValue);

        $this->expectTranslate('flash_create_error', ['%name%' => $expectedToStringValue], 'SonataAdminBundle');

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('getData')
            ->willReturn($object);

        $this->request->setMethod('POST');

        $formView = $this->createMock(FormView::class);

        $form->expects($this->any())
            ->method('createView')
            ->willReturn($formView);

        $this->assertLoggerLogsModelManagerException($this->admin, 'create');

        $this->assertInstanceOf(Response::class, $this->controller->createAction($this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('create', $this->parameters['action']);
        $this->assertInstanceOf(FormView::class, $this->parameters['form']);
        $this->assertSame($object, $this->parameters['object']);

        $this->assertSame(['sonata_flash_error' => ['flash_create_error']], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/edit.html.twig', $this->template);
    }

    public function testCreateActionAjaxSuccess(): void
    {
        $object = new \stdClass();

        $this->admin->expects($this->exactly(2))
            ->method('checkAccess')
            ->willReturnCallback(static function ($name, $objectIn = null) use ($object) {
                if ('create' !== $name) {
                    return false;
                }

                if (null === $objectIn) {
                    return true;
                }

                return $objectIn === $object;
            });

        $this->admin->expects($this->once())
            ->method('getNewInstance')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('create')
            ->willReturnArgument(0);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('getData')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $this->admin->expects($this->once())
            ->method('getNormalizedIdentifier')
            ->with($this->equalTo($object))
            ->willReturn('foo_normalized');

        $this->admin->expects($this->once())
            ->method('toString')
            ->willReturn('foo');

        $this->request->setMethod('POST');
        $this->request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->createAction($this->request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(json_encode(['result' => 'ok', 'objectId' => 'foo_normalized', 'objectName' => 'foo']), $response->getContent());
        $this->assertSame([], $this->session->getFlashBag()->all());
    }

    public function testCreateActionAjaxError(): void
    {
        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('create'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getNewInstance')
            ->willReturn($object);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(false);

        $formError = $this->createMock(FormError::class);
        $formError->expects($this->atLeastOnce())
            ->method('getMessage')
            ->willReturn('Form error message');

        $form->expects($this->once())
            ->method('getErrors')
            ->with(true)
            ->willReturn([$formError]);

        $this->request->setMethod('POST');
        $this->request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $this->request->headers->set('Accept', 'application/json');

        $this->assertInstanceOf(JsonResponse::class, $response = $this->controller->createAction($this->request));
        $this->assertJsonStringEqualsJsonString('{"result":"error","errors":["Form error message"]}', $response->getContent());
    }

    /**
     * @legacy
     * @expectedDeprecation In next major version response will return 406 NOT ACCEPTABLE without `Accept: application/json`
     */
    public function testCreateActionAjaxErrorWithoutAcceptApplicationJson(): void
    {
        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('create'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getNewInstance')
            ->willReturn($object);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(false);

        $this->request->setMethod('POST');
        $this->request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $formView = $this->createMock(FormView::class);
        $form
            ->method('createView')
            ->willReturn($formView);

        $this->assertInstanceOf(Response::class, $response = $this->controller->createAction($this->request));
        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/ajax_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);
        $this->assertSame('create', $this->parameters['action']);
        $this->assertInstanceOf(FormView::class, $this->parameters['form']);
        $this->assertSame($object, $this->parameters['object']);
        $this->assertSame(['sonata_flash_error' => [0 => null]], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/edit.html.twig', $this->template);
    }

    public function testCreateActionWithPreview(): void
    {
        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('create'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getNewInstance')
            ->willReturn($object);

        $form = $this->createMock(Form::class);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('stdClass');

        $this->admin->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $form->expects($this->once())
            ->method('all')
            ->willReturn(['field' => 'fielddata']);

        $this->admin->expects($this->once())
            ->method('supportsPreviewMode')
            ->willReturn(true);

        $formView = $this->createMock(FormView::class);

        $form->expects($this->any())
            ->method('createView')
            ->willReturn($formView);

        $form->expects($this->once())
            ->method('isSubmitted')
            ->willReturn(true);

        $form->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $this->request->setMethod('POST');
        $this->request->request->set('btn_preview', 'Preview');

        $this->assertInstanceOf(Response::class, $this->controller->createAction($this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('create', $this->parameters['action']);
        $this->assertInstanceOf(FormView::class, $this->parameters['form']);
        $this->assertSame($object, $this->parameters['object']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/preview.html.twig', $this->template);
    }

    public function testExportActionAccessDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('export'))
            ->will($this->throwException(new AccessDeniedException()));

        $this->controller->exportAction($this->request);
    }

    public function testExportActionWrongFormat(): void
    {
        $this->expectException(\RuntimeException::class, 'Export in format `csv` is not allowed for class: `Foo`. Allowed formats are: `json`');

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('export'))
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('getExportFormats')
            ->willReturn(['json']);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $this->request->query->set('format', 'csv');

        $this->controller->exportAction($this->request);
    }

    public function testExportAction(): void
    {
        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('export'))
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('getExportFormats')
            ->willReturn(['json']);

        $dataSourceIterator = $this->createMock(SourceIteratorInterface::class);

        $this->admin->expects($this->once())
            ->method('getDataSourceIterator')
            ->willReturn($dataSourceIterator);

        $this->request->query->set('format', 'json');

        $response = $this->controller->exportAction($this->request);
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->session->getFlashBag()->all());
    }

    public function testHistoryActionAccessDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->admin->expects($this->any())
            ->method('getObject')
            ->willReturn(new \StdClass());

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('history'))
            ->will($this->throwException(new AccessDeniedException()));

        $this->controller->historyAction(null, $this->request);
    }

    public function testHistoryActionNotFoundException(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn(false);

        $this->controller->historyAction(null, $this->request);
    }

    public function testHistoryActionNoReader(): void
    {
        $this->expectException(NotFoundHttpException::class, 'unable to find the audit reader for class : Foo');

        $this->request->query->set('id', 123);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('history'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $this->auditManager->expects($this->once())
            ->method('hasReader')
            ->with($this->equalTo('Foo'))
            ->willReturn(false);

        $this->controller->historyAction(null, $this->request);
    }

    public function testHistoryAction(): void
    {
        $this->request->query->set('id', 123);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('history'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $this->auditManager->expects($this->once())
            ->method('hasReader')
            ->with($this->equalTo('Foo'))
            ->willReturn(true);

        $reader = $this->createMock(AuditReaderInterface::class);

        $this->auditManager->expects($this->once())
            ->method('getReader')
            ->with($this->equalTo('Foo'))
            ->willReturn($reader);

        $reader->expects($this->once())
            ->method('findRevisions')
            ->with($this->equalTo('Foo'), $this->equalTo(123))
            ->willReturn([]);

        $this->assertInstanceOf(Response::class, $this->controller->historyAction(null, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('history', $this->parameters['action']);
        $this->assertSame([], $this->parameters['revisions']);
        $this->assertSame($object, $this->parameters['object']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/history.html.twig', $this->template);
    }

    public function testAclActionAclNotEnabled(): void
    {
        $this->expectException(NotFoundHttpException::class, 'ACL are not enabled for this admin');

        $this->controller->aclAction(null, $this->request);
    }

    public function testAclActionNotFoundException(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->admin->expects($this->once())
            ->method('isAclEnabled')
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn(false);

        $this->controller->aclAction(null, $this->request);
    }

    public function testAclActionAccessDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->admin->expects($this->once())
            ->method('isAclEnabled')
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('acl'), $this->equalTo($object))
            ->will($this->throwException(new AccessDeniedException()));

        $this->controller->aclAction(null, $this->request);
    }

    public function testAclAction(): void
    {
        $this->request->query->set('id', 123);

        $this->admin->expects($this->once())
            ->method('isAclEnabled')
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('checkAccess')
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('getSecurityInformation')
            ->willReturn([]);

        $this->adminObjectAclManipulator->expects($this->once())
            ->method('getMaskBuilderClass')
            ->willReturn(AdminPermissionMap::class);

        $aclUsersForm = $this->getMockBuilder(Form::class)
            ->disableOriginalConstructor()
            ->getMock();

        $aclUsersForm->expects($this->once())
            ->method('createView')
            ->willReturn($this->createMock(FormView::class));

        $aclRolesForm = $this->getMockBuilder(Form::class)
            ->disableOriginalConstructor()
            ->getMock();

        $aclRolesForm->expects($this->once())
            ->method('createView')
            ->willReturn($this->createMock(FormView::class));

        $this->adminObjectAclManipulator->expects($this->once())
            ->method('createAclUsersForm')
            ->with($this->isInstanceOf(AdminObjectAclData::class))
            ->willReturn($aclUsersForm);

        $this->adminObjectAclManipulator->expects($this->once())
            ->method('createAclRolesForm')
            ->with($this->isInstanceOf(AdminObjectAclData::class))
            ->willReturn($aclRolesForm);

        $aclSecurityHandler = $this->getMockBuilder(AclSecurityHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $aclSecurityHandler->expects($this->any())
            ->method('getObjectPermissions')
            ->willReturn([]);

        $this->admin->expects($this->any())
            ->method('getSecurityHandler')
            ->willReturn($aclSecurityHandler);

        $this->assertInstanceOf(Response::class, $this->controller->aclAction(null, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('acl', $this->parameters['action']);
        $this->assertSame([], $this->parameters['permissions']);
        $this->assertSame($object, $this->parameters['object']);
        $this->assertInstanceOf(\ArrayIterator::class, $this->parameters['users']);
        $this->assertInstanceOf(\ArrayIterator::class, $this->parameters['roles']);
        $this->assertInstanceOf(FormView::class, $this->parameters['aclUsersForm']);
        $this->assertInstanceOf(FormView::class, $this->parameters['aclRolesForm']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/acl.html.twig', $this->template);
    }

    public function testAclActionInvalidUpdate(): void
    {
        $this->request->query->set('id', 123);
        $this->request->request->set(AdminObjectAclManipulator::ACL_USERS_FORM_NAME, []);

        $this->admin->expects($this->once())
            ->method('isAclEnabled')
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('checkAccess')
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('getSecurityInformation')
            ->willReturn([]);

        $this->adminObjectAclManipulator->expects($this->once())
            ->method('getMaskBuilderClass')
            ->willReturn(AdminPermissionMap::class);

        $aclUsersForm = $this->getMockBuilder(Form::class)
            ->disableOriginalConstructor()
            ->getMock();

        $aclUsersForm->expects($this->once())
            ->method('isValid')
            ->willReturn(false);

        $aclUsersForm->expects($this->once())
            ->method('createView')
            ->willReturn($this->createMock(FormView::class));

        $aclRolesForm = $this->getMockBuilder(Form::class)
            ->disableOriginalConstructor()
            ->getMock();

        $aclRolesForm->expects($this->once())
            ->method('createView')
            ->willReturn($this->createMock(FormView::class));

        $this->adminObjectAclManipulator->expects($this->once())
            ->method('createAclUsersForm')
            ->with($this->isInstanceOf(AdminObjectAclData::class))
            ->willReturn($aclUsersForm);

        $this->adminObjectAclManipulator->expects($this->once())
            ->method('createAclRolesForm')
            ->with($this->isInstanceOf(AdminObjectAclData::class))
            ->willReturn($aclRolesForm);

        $aclSecurityHandler = $this->getMockBuilder(AclSecurityHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $aclSecurityHandler->expects($this->any())
            ->method('getObjectPermissions')
            ->willReturn([]);

        $this->admin->expects($this->any())
            ->method('getSecurityHandler')
            ->willReturn($aclSecurityHandler);

        $this->request->setMethod('POST');

        $this->assertInstanceOf(Response::class, $this->controller->aclAction(null, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('acl', $this->parameters['action']);
        $this->assertSame([], $this->parameters['permissions']);
        $this->assertSame($object, $this->parameters['object']);
        $this->assertInstanceOf(\ArrayIterator::class, $this->parameters['users']);
        $this->assertInstanceOf(\ArrayIterator::class, $this->parameters['roles']);
        $this->assertInstanceOf(FormView::class, $this->parameters['aclUsersForm']);
        $this->assertInstanceOf(FormView::class, $this->parameters['aclRolesForm']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/acl.html.twig', $this->template);
    }

    public function testAclActionSuccessfulUpdate(): void
    {
        $this->request->query->set('id', 123);
        $this->request->request->set(AdminObjectAclManipulator::ACL_ROLES_FORM_NAME, []);

        $this->admin->expects($this->once())
            ->method('isAclEnabled')
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('checkAccess')
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('getSecurityInformation')
            ->willReturn([]);

        $this->adminObjectAclManipulator->expects($this->once())
            ->method('getMaskBuilderClass')
            ->willReturn(AdminPermissionMap::class);

        $aclUsersForm = $this->getMockBuilder(Form::class)
            ->disableOriginalConstructor()
            ->getMock();

        $aclUsersForm->expects($this->any())
            ->method('createView')
            ->willReturn($this->createMock(FormView::class));

        $aclRolesForm = $this->getMockBuilder(Form::class)
            ->disableOriginalConstructor()
            ->getMock();

        $aclRolesForm->expects($this->any())
            ->method('createView')
            ->willReturn($this->createMock(FormView::class));

        $aclRolesForm->expects($this->once())
            ->method('isValid')
            ->willReturn(true);

        $this->adminObjectAclManipulator->expects($this->once())
            ->method('createAclUsersForm')
            ->with($this->isInstanceOf(AdminObjectAclData::class))
            ->willReturn($aclUsersForm);

        $this->adminObjectAclManipulator->expects($this->once())
            ->method('createAclRolesForm')
            ->with($this->isInstanceOf(AdminObjectAclData::class))
            ->willReturn($aclRolesForm);

        $aclSecurityHandler = $this->getMockBuilder(AclSecurityHandler::class)
            ->disableOriginalConstructor()
            ->getMock();

        $aclSecurityHandler->expects($this->any())
            ->method('getObjectPermissions')
            ->willReturn([]);

        $this->admin->expects($this->any())
            ->method('getSecurityHandler')
            ->willReturn($aclSecurityHandler);

        $this->expectTranslate('flash_acl_edit_success', [], 'SonataAdminBundle');

        $this->request->setMethod('POST');

        $response = $this->controller->aclAction(null, $this->request);

        $this->assertInstanceOf(RedirectResponse::class, $response);

        $this->assertSame(['flash_acl_edit_success'], $this->session->getFlashBag()->get('sonata_flash_success'));
        $this->assertSame('stdClass_acl', $response->getTargetUrl());
    }

    public function testHistoryViewRevisionActionAccessDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->admin->expects($this->any())
            ->method('getObject')
            ->willReturn(new \StdClass());

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('historyViewRevision'))
            ->will($this->throwException(new AccessDeniedException()));

        $this->controller->historyViewRevisionAction(null, null, $this->request);
    }

    public function testHistoryViewRevisionActionNotFoundException(): void
    {
        $this->expectException(NotFoundHttpException::class, 'unable to find the object with id: 123');

        $this->request->query->set('id', 123);

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn(false);

        $this->controller->historyViewRevisionAction(null, null, $this->request);
    }

    public function testHistoryViewRevisionActionNoReader(): void
    {
        $this->expectException(NotFoundHttpException::class, 'unable to find the audit reader for class : Foo');

        $this->request->query->set('id', 123);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('historyViewRevision'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $this->auditManager->expects($this->once())
            ->method('hasReader')
            ->with($this->equalTo('Foo'))
            ->willReturn(false);

        $this->controller->historyViewRevisionAction(null, null, $this->request);
    }

    public function testHistoryViewRevisionActionNotFoundRevision(): void
    {
        $this->expectException(NotFoundHttpException::class, 'unable to find the targeted object `123` from the revision `456` with classname : `Foo`');

        $this->request->query->set('id', 123);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('historyViewRevision'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $this->auditManager->expects($this->once())
            ->method('hasReader')
            ->with($this->equalTo('Foo'))
            ->willReturn(true);

        $reader = $this->createMock(AuditReaderInterface::class);

        $this->auditManager->expects($this->once())
            ->method('getReader')
            ->with($this->equalTo('Foo'))
            ->willReturn($reader);

        $reader->expects($this->once())
            ->method('find')
            ->with($this->equalTo('Foo'), $this->equalTo(123), $this->equalTo(456))
            ->willReturn(null);

        $this->controller->historyViewRevisionAction(123, 456, $this->request);
    }

    public function testHistoryViewRevisionAction(): void
    {
        $this->request->query->set('id', 123);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('historyViewRevision'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $this->auditManager->expects($this->once())
            ->method('hasReader')
            ->with($this->equalTo('Foo'))
            ->willReturn(true);

        $reader = $this->createMock(AuditReaderInterface::class);

        $this->auditManager->expects($this->once())
            ->method('getReader')
            ->with($this->equalTo('Foo'))
            ->willReturn($reader);

        $objectRevision = new \stdClass();
        $objectRevision->revision = 456;

        $reader->expects($this->once())
            ->method('find')
            ->with($this->equalTo('Foo'), $this->equalTo(123), $this->equalTo(456))
            ->willReturn($objectRevision);

        $this->admin->expects($this->once())
            ->method('setSubject')
            ->with($this->equalTo($objectRevision))
            ->willReturn(null);

        $fieldDescriptionCollection = new FieldDescriptionCollection();
        $this->admin->expects($this->once())
            ->method('getShow')
            ->willReturn($fieldDescriptionCollection);

        $this->assertInstanceOf(Response::class, $this->controller->historyViewRevisionAction(123, 456, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('show', $this->parameters['action']);
        $this->assertSame($objectRevision, $this->parameters['object']);
        $this->assertSame($fieldDescriptionCollection, $this->parameters['elements']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/show.html.twig', $this->template);
    }

    public function testHistoryCompareRevisionsActionAccessDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('historyCompareRevisions'))
            ->will($this->throwException(new AccessDeniedException()));

        $this->controller->historyCompareRevisionsAction(null, null, null, $this->request);
    }

    public function testHistoryCompareRevisionsActionNotFoundException(): void
    {
        $this->expectException(NotFoundHttpException::class, 'unable to find the object with id: 123');

        $this->request->query->set('id', 123);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('historyCompareRevisions'))
            ->willReturn(true);

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn(false);

        $this->controller->historyCompareRevisionsAction(null, null, null, $this->request);
    }

    public function testHistoryCompareRevisionsActionNoReader(): void
    {
        $this->expectException(NotFoundHttpException::class, 'unable to find the audit reader for class : Foo');

        $this->request->query->set('id', 123);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('historyCompareRevisions'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $this->auditManager->expects($this->once())
            ->method('hasReader')
            ->with($this->equalTo('Foo'))
            ->willReturn(false);

        $this->controller->historyCompareRevisionsAction(null, null, null, $this->request);
    }

    public function testHistoryCompareRevisionsActionNotFoundBaseRevision(): void
    {
        $this->expectException(NotFoundHttpException::class, 'unable to find the targeted object `123` from the revision `456` with classname : `Foo`');

        $this->request->query->set('id', 123);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('historyCompareRevisions'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $this->auditManager->expects($this->once())
            ->method('hasReader')
            ->with($this->equalTo('Foo'))
            ->willReturn(true);

        $reader = $this->createMock(AuditReaderInterface::class);

        $this->auditManager->expects($this->once())
            ->method('getReader')
            ->with($this->equalTo('Foo'))
            ->willReturn($reader);

        // once because it will not be found and therefore the second call won't be executed
        $reader->expects($this->once())
            ->method('find')
            ->with($this->equalTo('Foo'), $this->equalTo(123), $this->equalTo(456))
            ->willReturn(null);

        $this->controller->historyCompareRevisionsAction(123, 456, 789, $this->request);
    }

    public function testHistoryCompareRevisionsActionNotFoundCompareRevision(): void
    {
        $this->expectException(NotFoundHttpException::class, 'unable to find the targeted object `123` from the revision `789` with classname : `Foo`');

        $this->request->query->set('id', 123);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('historyCompareRevisions'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $this->auditManager->expects($this->once())
            ->method('hasReader')
            ->with($this->equalTo('Foo'))
            ->willReturn(true);

        $reader = $this->createMock(AuditReaderInterface::class);

        $this->auditManager->expects($this->once())
            ->method('getReader')
            ->with($this->equalTo('Foo'))
            ->willReturn($reader);

        $objectRevision = new \stdClass();
        $objectRevision->revision = 456;

        // first call should return, so the second call will throw an exception
        $reader->expects($this->at(0))
            ->method('find')
            ->with($this->equalTo('Foo'), $this->equalTo(123), $this->equalTo(456))
            ->willReturn($objectRevision);

        $reader->expects($this->at(1))
            ->method('find')
            ->with($this->equalTo('Foo'), $this->equalTo(123), $this->equalTo(789))
            ->willReturn(null);

        $this->controller->historyCompareRevisionsAction(123, 456, 789, $this->request);
    }

    public function testHistoryCompareRevisionsActionAction(): void
    {
        $this->request->query->set('id', 123);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('historyCompareRevisions'))
            ->willReturn(true);

        $object = new \stdClass();

        $this->admin->expects($this->once())
            ->method('getObject')
            ->willReturn($object);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $this->auditManager->expects($this->once())
            ->method('hasReader')
            ->with($this->equalTo('Foo'))
            ->willReturn(true);

        $reader = $this->createMock(AuditReaderInterface::class);

        $this->auditManager->expects($this->once())
            ->method('getReader')
            ->with($this->equalTo('Foo'))
            ->willReturn($reader);

        $objectRevision = new \stdClass();
        $objectRevision->revision = 456;

        $compareObjectRevision = new \stdClass();
        $compareObjectRevision->revision = 789;

        $reader->expects($this->at(0))
            ->method('find')
            ->with($this->equalTo('Foo'), $this->equalTo(123), $this->equalTo(456))
            ->willReturn($objectRevision);

        $reader->expects($this->at(1))
            ->method('find')
            ->with($this->equalTo('Foo'), $this->equalTo(123), $this->equalTo(789))
            ->willReturn($compareObjectRevision);

        $this->admin->expects($this->once())
            ->method('setSubject')
            ->with($this->equalTo($objectRevision))
            ->willReturn(null);

        $fieldDescriptionCollection = new FieldDescriptionCollection();
        $this->admin->expects($this->once())
            ->method('getShow')
            ->willReturn($fieldDescriptionCollection);

        $this->assertInstanceOf(Response::class, $this->controller->historyCompareRevisionsAction(123, 456, 789, $this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('show', $this->parameters['action']);
        $this->assertSame($objectRevision, $this->parameters['object']);
        $this->assertSame($compareObjectRevision, $this->parameters['object_compare']);
        $this->assertSame($fieldDescriptionCollection, $this->parameters['elements']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/show_compare.html.twig', $this->template);
    }

    public function testBatchActionWrongMethod(): void
    {
        $this->expectException(NotFoundHttpException::class, 'Invalid request type "GET", POST expected');

        $this->controller->batchAction($this->request);
    }

    /**
     * NEXT_MAJOR: Remove this legacy group.
     *
     * @group legacy
     */
    public function testBatchActionActionNotDefined(): void
    {
        $this->expectException(\RuntimeException::class, 'The `foo` batch action is not defined');

        $batchActions = [];

        $this->admin->expects($this->once())
            ->method('getBatchActions')
            ->willReturn($batchActions);

        $this->request->setMethod('POST');
        $this->request->request->set('data', json_encode(['action' => 'foo', 'idx' => ['123', '456'], 'all_elements' => false]));
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.batch');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $this->controller->batchAction($this->request);
    }

    public function testBatchActionActionInvalidCsrfToken(): void
    {
        $this->request->setMethod('POST');
        $this->request->request->set('data', json_encode(['action' => 'foo', 'idx' => ['123', '456'], 'all_elements' => false]));
        $this->request->request->set('_sonata_csrf_token', 'CSRF-INVALID');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        try {
            $this->controller->batchAction($this->request);
        } catch (HttpException $e) {
            $this->assertSame('The csrf token is not valid, CSRF attack?', $e->getMessage());
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function testBatchActionActionWithDisabledCsrfProtection(): void
    {
        $this->request->setMethod('POST');
        $this->request->request->set('data', json_encode(['action' => 'foo', 'idx' => ['123', '456'], 'all_elements' => false]));

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(false);

        $this->admin->expects($this->once())
            ->method('getBatchActions')
            ->willReturn(['foo' => ['label' => 'foo']]);

        $datagrid = $this->createMock(DatagridInterface::class);

        $this->admin->expects($this->once())
            ->method('getDatagrid')
            ->willReturn($datagrid);

        $datagrid->expects($this->once())
            ->method('buildPager');

        $form = $this->createMock(FormInterface::class);

        $datagrid->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $formView = $this->createMock(FormView::class);

        $form->expects($this->once())
            ->method('createView')
            ->willReturn($formView);

        $this->controller->batchAction($this->request);
    }

    /**
     * NEXT_MAJOR: Remove this legacy group.
     *
     * @group legacy
     */
    public function testBatchActionMethodNotExist(): void
    {
        $this->expectException(\RuntimeException::class, 'A `Sonata\AdminBundle\Controller\CRUDController::batchActionFoo` method must be callable');

        $batchActions = ['foo' => ['label' => 'Foo Bar', 'ask_confirmation' => false]];

        $this->admin->expects($this->once())
            ->method('getBatchActions')
            ->willReturn($batchActions);

        $datagrid = $this->createMock(DatagridInterface::class);
        $this->admin->expects($this->once())
            ->method('getDatagrid')
            ->willReturn($datagrid);

        $this->request->setMethod('POST');
        $this->request->request->set('data', json_encode(['action' => 'foo', 'idx' => ['123', '456'], 'all_elements' => false]));
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.batch');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $this->controller->batchAction($this->request);
    }

    /**
     * NEXT_MAJOR: Remove this legacy group.
     *
     * @group legacy
     */
    public function testBatchActionWithoutConfirmation(): void
    {
        $batchActions = ['delete' => ['label' => 'Foo Bar', 'ask_confirmation' => false]];

        $this->admin->expects($this->once())
            ->method('getBatchActions')
            ->willReturn($batchActions);

        $datagrid = $this->createMock(DatagridInterface::class);

        $query = $this->createMock(ProxyQueryInterface::class);
        $datagrid->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $this->admin->expects($this->once())
            ->method('getDatagrid')
            ->willReturn($datagrid);

        $modelManager = $this->createMock(ModelManagerInterface::class);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('batchDelete'))
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('getModelManager')
            ->willReturn($modelManager);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $modelManager->expects($this->once())
            ->method('addIdentifiersToQuery')
            ->with($this->equalTo('Foo'), $this->equalTo($query), $this->equalTo(['123', '456']))
            ->willReturn(true);

        $this->expectTranslate('flash_batch_delete_success', [], 'SonataAdminBundle');

        $this->request->setMethod('POST');
        $this->request->request->set('data', json_encode(['action' => 'delete', 'idx' => ['123', '456'], 'all_elements' => false]));
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.batch');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $result = $this->controller->batchAction($this->request);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(['flash_batch_delete_success'], $this->session->getFlashBag()->get('sonata_flash_success'));
        $this->assertSame('list', $result->getTargetUrl());
    }

    /**
     * NEXT_MAJOR: Remove this legacy group.
     *
     * @group legacy
     */
    public function testBatchActionWithoutConfirmation2(): void
    {
        $batchActions = ['delete' => ['label' => 'Foo Bar', 'ask_confirmation' => false]];

        $this->admin->expects($this->once())
            ->method('getBatchActions')
            ->willReturn($batchActions);

        $datagrid = $this->createMock(DatagridInterface::class);

        $query = $this->createMock(ProxyQueryInterface::class);
        $datagrid->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $this->admin->expects($this->once())
            ->method('getDatagrid')
            ->willReturn($datagrid);

        $modelManager = $this->createMock(ModelManagerInterface::class);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('batchDelete'))
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('getModelManager')
            ->willReturn($modelManager);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $modelManager->expects($this->once())
            ->method('addIdentifiersToQuery')
            ->with($this->equalTo('Foo'), $this->equalTo($query), $this->equalTo(['123', '456']))
            ->willReturn(true);

        $this->expectTranslate('flash_batch_delete_success', [], 'SonataAdminBundle');

        $this->request->setMethod('POST');
        $this->request->request->set('action', 'delete');
        $this->request->request->set('idx', ['123', '456']);
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.batch');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $result = $this->controller->batchAction($this->request);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(['flash_batch_delete_success'], $this->session->getFlashBag()->get('sonata_flash_success'));
        $this->assertSame('list', $result->getTargetUrl());
    }

    /**
     * NEXT_MAJOR: Remove this legacy group.
     *
     * @group legacy
     */
    public function testBatchActionWithConfirmation(): void
    {
        $batchActions = ['delete' => ['label' => 'Foo Bar', 'translation_domain' => 'FooBarBaz', 'ask_confirmation' => true]];

        $this->admin->expects($this->once())
            ->method('getBatchActions')
            ->willReturn($batchActions);

        $data = ['action' => 'delete', 'idx' => ['123', '456'], 'all_elements' => false];

        $this->request->setMethod('POST');
        $this->request->request->set('data', json_encode($data));
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.batch');

        $datagrid = $this->createMock(DatagridInterface::class);

        $this->admin->expects($this->once())
            ->method('getDatagrid')
            ->willReturn($datagrid);

        $form = $this->getMockBuilder(Form::class)
            ->disableOriginalConstructor()
            ->getMock();

        $form->expects($this->once())
            ->method('createView')
            ->willReturn($this->createMock(FormView::class));

        $datagrid->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $this->assertInstanceOf(Response::class, $this->controller->batchAction($this->request));

        $this->assertSame($this->admin, $this->parameters['admin']);
        $this->assertSame('@SonataAdmin/standard_layout.html.twig', $this->parameters['base_template']);
        $this->assertSame($this->pool, $this->parameters['admin_pool']);

        $this->assertSame('list', $this->parameters['action']);
        $this->assertSame($datagrid, $this->parameters['datagrid']);
        $this->assertInstanceOf(FormView::class, $this->parameters['form']);
        $this->assertSame($data, $this->parameters['data']);
        $this->assertSame('csrf-token-123_sonata.batch', $this->parameters['csrf_token']);
        $this->assertSame('Foo Bar', $this->parameters['action_label']);

        $this->assertSame([], $this->session->getFlashBag()->all());
        $this->assertSame('@SonataAdmin/CRUD/batch_confirmation.html.twig', $this->template);
    }

    /**
     * NEXT_MAJOR: Remove this legacy group.
     *
     * @group legacy
     */
    public function testBatchActionNonRelevantAction(): void
    {
        $controller = new BatchAdminController();
        $controller->setContainer($this->container);

        $batchActions = ['foo' => ['label' => 'Foo Bar', 'ask_confirmation' => false]];

        $this->admin->expects($this->once())
            ->method('getBatchActions')
            ->willReturn($batchActions);

        $datagrid = $this->createMock(DatagridInterface::class);

        $this->admin->expects($this->once())
            ->method('getDatagrid')
            ->willReturn($datagrid);

        $this->expectTranslate('flash_batch_empty', [], 'SonataAdminBundle');

        $this->request->setMethod('POST');
        $this->request->request->set('action', 'foo');
        $this->request->request->set('idx', ['789']);
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.batch');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $result = $controller->batchAction($this->request);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(['flash_batch_empty'], $this->session->getFlashBag()->get('sonata_flash_info'));
        $this->assertSame('list', $result->getTargetUrl());
    }

    public function testBatchActionWithCustomConfirmationTemplate(): void
    {
        $batchActions = ['delete' => ['label' => 'Foo Bar', 'ask_confirmation' => true, 'template' => 'custom_template.html.twig']];

        $this->admin->expects($this->once())
            ->method('getBatchActions')
            ->willReturn($batchActions);

        $data = ['action' => 'delete', 'idx' => ['123', '456'], 'all_elements' => false];

        $this->request->setMethod('POST');
        $this->request->request->set('data', json_encode($data));
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.batch');

        $datagrid = $this->createMock(DatagridInterface::class);

        $this->admin->expects($this->once())
            ->method('getDatagrid')
            ->willReturn($datagrid);

        $form = $this->createMock(Form::class);

        $form->expects($this->once())
            ->method('createView')
            ->willReturn($this->createMock(FormView::class));

        $datagrid->expects($this->once())
            ->method('getForm')
            ->willReturn($form);

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $this->controller->batchAction($this->request);

        $this->assertSame('custom_template.html.twig', $this->template);
    }

    /**
     * NEXT_MAJOR: Remove this legacy group.
     *
     * @group legacy
     */
    public function testBatchActionNonRelevantAction2(): void
    {
        $controller = new BatchAdminController();
        $controller->setContainer($this->container);

        $batchActions = ['foo' => ['label' => 'Foo Bar', 'ask_confirmation' => false]];

        $this->admin->expects($this->once())
            ->method('getBatchActions')
            ->willReturn($batchActions);

        $datagrid = $this->createMock(DatagridInterface::class);

        $this->admin->expects($this->once())
            ->method('getDatagrid')
            ->willReturn($datagrid);

        $this->expectTranslate('flash_foo_error', [], 'SonataAdminBundle');

        $this->request->setMethod('POST');
        $this->request->request->set('action', 'foo');
        $this->request->request->set('idx', ['999']);
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.batch');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $result = $controller->batchAction($this->request);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(['flash_foo_error'], $this->session->getFlashBag()->get('sonata_flash_info'));
        $this->assertSame('list', $result->getTargetUrl());
    }

    /**
     * NEXT_MAJOR: Remove this legacy group.
     *
     * @group legacy
     */
    public function testBatchActionNoItems(): void
    {
        $batchActions = ['delete' => ['label' => 'Foo Bar', 'ask_confirmation' => true]];

        $this->admin->expects($this->once())
            ->method('getBatchActions')
            ->willReturn($batchActions);

        $datagrid = $this->createMock(DatagridInterface::class);

        $this->admin->expects($this->once())
            ->method('getDatagrid')
            ->willReturn($datagrid);

        $this->expectTranslate('flash_batch_empty', [], 'SonataAdminBundle');

        $this->request->setMethod('POST');
        $this->request->request->set('action', 'delete');
        $this->request->request->set('idx', []);
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.batch');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $result = $this->controller->batchAction($this->request);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(['flash_batch_empty'], $this->session->getFlashBag()->get('sonata_flash_info'));
        $this->assertSame('list', $result->getTargetUrl());
    }

    /**
     * NEXT_MAJOR: Remove this legacy group.
     *
     * @group legacy
     */
    public function testBatchActionNoItemsEmptyQuery(): void
    {
        $controller = new BatchAdminController();
        $controller->setContainer($this->container);

        $batchActions = ['bar' => ['label' => 'Foo Bar', 'ask_confirmation' => false]];

        $this->admin->expects($this->once())
            ->method('getBatchActions')
            ->willReturn($batchActions);

        $datagrid = $this->createMock(DatagridInterface::class);

        $query = $this->createMock(ProxyQueryInterface::class);
        $datagrid->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $this->admin->expects($this->once())
            ->method('getDatagrid')
            ->willReturn($datagrid);

        $modelManager = $this->createMock(ModelManagerInterface::class);

        $this->admin->expects($this->any())
            ->method('getModelManager')
            ->willReturn($modelManager);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $this->request->setMethod('POST');
        $this->request->request->set('action', 'bar');
        $this->request->request->set('idx', []);
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.batch');

        $this->expectTranslate('flash_batch_no_elements_processed', [], 'SonataAdminBundle');
        $result = $controller->batchAction($this->request);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertRegExp('/Redirecting to list/', $result->getContent());
    }

    /**
     * NEXT_MAJOR: Remove this legacy group.
     *
     * @group legacy
     */
    public function testBatchActionWithRequesData(): void
    {
        $batchActions = ['delete' => ['label' => 'Foo Bar', 'ask_confirmation' => false]];

        $this->admin->expects($this->once())
            ->method('getBatchActions')
            ->willReturn($batchActions);

        $datagrid = $this->createMock(DatagridInterface::class);

        $query = $this->createMock(ProxyQueryInterface::class);
        $datagrid->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $this->admin->expects($this->once())
            ->method('getDatagrid')
            ->willReturn($datagrid);

        $modelManager = $this->createMock(ModelManagerInterface::class);

        $this->admin->expects($this->once())
            ->method('checkAccess')
            ->with($this->equalTo('batchDelete'))
            ->willReturn(true);

        $this->admin->expects($this->any())
            ->method('getModelManager')
            ->willReturn($modelManager);

        $this->admin->expects($this->any())
            ->method('getClass')
            ->willReturn('Foo');

        $modelManager->expects($this->once())
            ->method('addIdentifiersToQuery')
            ->with($this->equalTo('Foo'), $this->equalTo($query), $this->equalTo(['123', '456']))
            ->willReturn(true);

        $this->expectTranslate('flash_batch_delete_success', [], 'SonataAdminBundle');

        $this->request->setMethod('POST');
        $this->request->request->set('data', json_encode(['action' => 'delete', 'idx' => ['123', '456'], 'all_elements' => false]));
        $this->request->request->set('foo', 'bar');
        $this->request->request->set('_sonata_csrf_token', 'csrf-token-123_sonata.batch');

        $this->formBuilder->expects($this->once())
            ->method('getOption')
            ->with('csrf_protection')
            ->willReturn(true);

        $result = $this->controller->batchAction($this->request);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame(['flash_batch_delete_success'], $this->session->getFlashBag()->get('sonata_flash_success'));
        $this->assertSame('list', $result->getTargetUrl());
        $this->assertSame('bar', $this->request->request->get('foo'));
    }

    public function testItThrowsWhenCallingAnUndefinedMethod(): void
    {
        $this->expectException(
            \LogicException::class
        );
        $this->expectExceptionMessage(
            'Call to undefined method Sonata\AdminBundle\Controller\CRUDController::doesNotExist'
        );
        $this->controller->doesNotExist();
    }

    /**
     * @expectedDeprecation Method Sonata\AdminBundle\Controller\CRUDController::render has been renamed to Sonata\AdminBundle\Controller\CRUDController::renderWithExtraParams.
     */
    public function testRenderIsDeprecated(): void
    {
        $this->controller->render('toto.html.twig');
    }

    public function getCsrfProvider()
    {
        return $this->csrfProvider;
    }

    public function getToStringValues()
    {
        return [
            ['', ''],
            ['Foo', 'Foo'],
            ['&lt;a href=&quot;http://foo&quot;&gt;Bar&lt;/a&gt;', '<a href="http://foo">Bar</a>'],
            ['&lt;&gt;&amp;&quot;&#039;abcdefghijklmnopqrstuvwxyz*-+.,?_()[]\/', '<>&"\'abcdefghijklmnopqrstuvwxyz*-+.,?_()[]\/'],
        ];
    }

    private function assertLoggerLogsModelManagerException($subject, string $method): void
    {
        $exception = new ModelManagerException(
            $message = 'message',
            1234,
            new \Exception($previousExceptionMessage = 'very useful message')
        );

        $subject->expects($this->once())
            ->method($method)
            ->willReturnCallback(static function () use ($exception): void {
                throw $exception;
            });

        $this->logger->expects($this->once())
            ->method('error')
            ->with($message, [
                'exception' => $exception,
                'previous_exception_message' => $previousExceptionMessage,
            ]);
    }

    private function expectTranslate(
        string $id,
        array $parameters = [],
        ?string $domain = null,
        ?string $locale = null
    ): void {
        $this->translator->expects($this->once())
            ->method('trans')
            ->with($this->equalTo($id), $this->equalTo($parameters), $this->equalTo($domain), $this->equalTo($locale))
            ->willReturn($id);
    }
}
