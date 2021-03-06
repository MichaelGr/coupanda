<?php

namespace Maximaster\Coupanda;

use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Context;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Internals\DiscountCouponTable;
use Bitrix\Sale\Internals\DiscountTable;
use Maximaster\Coupanda\Generator\Collections\DigitsCollection;
use Maximaster\Coupanda\Generator\Collections\EnglishLettersCollection;
use Maximaster\Coupanda\Generator\Collections\RussianLettersCollection;
use Maximaster\Coupanda\Generator\CouponGenerator;
use Maximaster\Coupanda\Generator\CouponGeneratorException;
use Maximaster\Coupanda\Generator\SequenceGenerator;
use Maximaster\Coupanda\Generator\Template\SequenceTemplate;
use Maximaster\Coupanda\Generator\Template\SequenceTemplateInterface;
use Maximaster\Coupanda\Http\JsonResponse;
use Maximaster\Coupanda\Process\Process;
use Maximaster\Coupanda\Process\ProcessRepository;
use Maximaster\Coupanda\Process\ProcessSettings;

class CoupandaCouponGenerator extends \CBitrixComponent
{
    public function onPrepareComponentParams($params)
    {
        $params['CACHE_TIME'] = 0;
        $params['CACHE_TYPE'] = 'N';
        return parent::onPrepareComponentParams($params);
    }

    public function onIncludeComponentLang()
    {
        parent::onIncludeComponentLang();
        Loc::loadLanguageFile(__FILE__);
    }

    public function executeComponent()
    {
        $this->setFrameMode(false);
        $isAjax = $this->isAjaxRequest() && $this->request->isPost();

        try {
            $this->checkPermissions();
            $this->loadModules();
            if ($isAjax) {
                $this->handleAjax();
                return null;
            } else {
                return $this->handle();
            }
        } catch (\Exception $e) {
            \ShowError($e->getMessage());
            return null;
        }
    }

    protected function loadModules()
    {
        array_map(function ($moduleId) {
            if (!Loader::includeModule($moduleId)) {
                throw new SystemException('Модуль ' . $moduleId . ' не установлен');
            }
        }, ['sale', 'maximaster.coupanda']);

    }

    /**
     * @throws SystemException
     */
    protected function checkPermissions()
    {
        global $APPLICATION;
        $permission = $APPLICATION->GetGroupRight('maximaster.coupanda');
        if ($permission < 'W') {
            throw new SystemException('Недостаточно прав для использования генератора');
        }
    }

    protected function isAjaxRequest()
    {
        return isset($this->request['ajax_action']) && $this->request->isAjaxRequest();
    }

    protected function handle()
    {
        $this->setPageParameters();
        $this->setAdminContextMenu();
        $this->arResult['DISCOUNTS'] = $this->getDiscountList();
        $this->arResult['COUPON_TYPES'] = $this->getCouponTypes();
        $this->arResult['LINKS'] = $this->getLinks();
        $this->includeComponentTemplate();
    }

    protected function setPageParameters()
    {
        global $APPLICATION;
        $APPLICATION->SetTitle('Генератор купонов');
    }

    protected function setAdminContextMenu()
    {
        if (!$this->request->isAdminSection()) {
            return;
        }

        /*$menu = array(
            array(
                "TEXT" => Loc::getMessage("BTN_TO_LIST"),
                "TITLE" => Loc::getMessage("BTN_TO_LIST"),
                "LINK" => "/bitrix/admin/coupons_list.php?lang=".LANG,
                "ICON" => "btn_list"
            ),
        );

        $menu[] = [
            "TEXT" => Loc::getMessage("BTN_TO_LIST"),
            "TITLE" => Loc::getMessage("BTN_TO_LIST"),
            "LINK" => "/bitrix/admin/coupons_list.php?lang=".LANG,
            "ICON" => ""
        ];
        $context = new \CAdminContextMenu($menu);
        $context->Show();
        */
    }

    protected function getDiscountList()
    {
        $q = DiscountTable::query()
            ->addOrder('ID', 'desc')
            ->setSelect(['ID', 'NAME', 'ACTIVE', 'ACTIVE_FROM', 'ACTIVE_TO']);

        $defaultValue = '';
        $requestedValue = $this->request->get('DISCOUNT_ID');
        $selectedValue = $requestedValue ? $requestedValue : $defaultValue;

        $discounts = [];
        $discountList = $q->exec();
        while ($discount = $discountList->fetch()) {

            $discountFormOption = [
                'NAME' => "[{$discount['ID']}] {$discount['NAME']}",
                'VALUE' => $discount['ID'],
                'SELECTED' => $discount['ID'] == $selectedValue,
            ];

            $discount['FORM_OPTION'] = $discountFormOption;

            $discounts[] = $discount;
        }

        return $discounts;
    }

    protected function getLinks()
    {
        return [
            'new_discount' => '/bitrix/admin/sale_discount_edit.php?lang=' . LANGUAGE_ID
        ];
    }

    protected function getCouponTypes()
    {
        $defaultValue = DiscountCouponTable::TYPE_ONE_ORDER;
        $requestedValue = $this->request->get('TYPE');
        $selectedValue = $requestedValue ? $requestedValue : $defaultValue;

        $types = DiscountCouponTable::getCouponTypes(true);
        $values = [];
        foreach ($types as $code => $name) {
            $values[] = [
                'VALUE' => $code,
                'NAME' => $name,
                'SELECTED' => $selectedValue == $code
            ];
        }

        return $values;
    }

    protected function handleAjax()
    {
        global $APPLICATION;
        $APPLICATION->RestartBuffer();
        $request = $this->request;
        $response = Context::getCurrent()->getResponse();
        $response->clear();
        try {
            $response->addHeader('Content-Type', 'application/json');
        } catch (ArgumentNullException $e) {
        } catch (ArgumentOutOfRangeException $e) {
        }

        try {
            if (!check_bitrix_sessid()) {
                throw new SystemException('Ваша сессия истекла');
            }

            $ajaxResponse = $this->handleAjaxAction($request);
            if (!($ajaxResponse instanceof JsonResponse)) {
                throw new \LogicException('Произошла неизвестная ошибка работы с модулем. Обратитесь в техническую поддержку');
            }

        } catch (\Exception $e) {
            $ajaxResponse = new JsonResponse();
            $ajaxResponse->setStatus(500);
            $ajaxResponse->setMessage($e->getMessage());
        }

        $response->flush($ajaxResponse->render());

        \CMain::FinalActions();
        die;
    }

    protected function handleAjaxAction(HttpRequest $request)
    {
        $action = $request['ajax_action'];

        switch ($action) {
            case 'generation_preview':
                return $this->ajaxGenerationPreview($request);
                break;
            case 'generation_start':
                return $this->ajaxGenerationStart($request);
                break;
            case 'generation_step':
                return $this->ajaxGenerationProcess($request);
                break;
            case 'generation_finish':
                return $this->ajaxGenerationFinish($request);
                break;
        }

        $response = new JsonResponse();
        $response->setStatus(400);
        $response->setMessage('Действие ' . $action . ' недоступно');
        return $response;
    }

    protected function ajaxGenerationProcess(HttpRequest $request)
    {
        $response = new JsonResponse();
        $stepCount = 1000;
        $stepTime = 5;

        $process = $this->getProcess($request);

        if (!$process->isInProgress()) {
            $response->setStatus(400);
            $response->setMessage('Попытка выполнить запрос на генерацию без инициализации. Попробуйте начать процесс заново');
            return $response;
        }

        $countToFinish = $process->getSettings()->getCount() - $process->getProcessedCount();
        $countToGenerate = $countToFinish > $stepCount ? $stepCount : $countToFinish;

        $timeStart = microtime(true);
        $totalTimeSpent = microtime(true) - $timeStart;

        $template = $process->getSettings()->getTemplate();
        $couponGenerator = $this->getCouponGenerator(
            $this->getSequenceGenerator($this->getSequenceTemplate($template)),
            $process
        );

        $generatedCoupons = [];

        try {
            while ($countToGenerate > 0 && $totalTimeSpent < $stepTime) {
                $generationResult = $couponGenerator->generate($countToGenerate > 10 ? 10 : $countToGenerate);
                $createdCoupons = $generationResult->getData()['COUPONS'];
                $process->incrementProcessedCount(count($createdCoupons));
                $generatedCoupons += $createdCoupons;
                $countToGenerate -= count($createdCoupons);
                if (!$generationResult->isSuccess()) {
                    throw new CouponGeneratorException(
                        $generationResult->getErrorCollection()->current()->getMessage()
                    );
                }
                $totalTimeSpent = microtime(true) - $timeStart;
            }

            $response->setStatus(201);
            $response->setPayload([
                'progress_html' => $this->renderProgressHtml($process),
                'next_action' => $process->getProgressPercentage() < 100 ? 'generation_step' : 'generation_finish',
                'report' => $this->getReport($process),
                //'coupons' => $generatedCoupons
            ]);

        } catch (\Exception $e) {
            $response->setStatus(500);
            $response->setMessage($e->getMessage());
            $response->setPayload([
                'progress_html' => $this->renderProgressHtml($process),
                'report' => $this->getReport($process),
            ]);
            $process->setFinishedAt(new DateTime());
        }

        ProcessRepository::save($process);

        return $response;
    }

    protected function ajaxGenerationStart(HttpRequest $request)
    {
        $response = new JsonResponse();
        $settings = ProcessSettings::createFromRequest($request);

        $settingsValidationResult = $settings->validate();
        if (!$settingsValidationResult->isSuccess()) {
            $response->setStatus(400);
            $message = implode('. ', $settingsValidationResult->getErrorMessages());
            $response->setMessage($message);
            return $response;
        }

        $process = new Process();
        $process->setStartedAt(new DateTime());
        $process->setSettings($settings);

        ProcessRepository::save($process);

        $response->setStatus(200);
        $response->setMessage('Инициализация успешно завершена');
        $response->setPayload([
            'pid' => $process->getId(),
            'progress_html' => $this->renderProgressHtml($process),
            'next_action' => 'generation_step',
            'report' => $this->getReport($process),
        ]);

        return $response;
    }

    protected function ajaxGenerationFinish(HttpRequest $request)
    {
        $response = new JsonResponse();
        $process = $this->getProcess($request);

        if (!$process) {
            $response->setStatus(400);
            $response->setMessage('Не удалось подцепить процесс. Попробуйте начать процесс заново');
            return $response;
        }

        if ($process->getProgressPercentage() < 100) {
            $response->setStatus(400);
            $response->setMessage('Процесс еще не завершен');
            return $response;
        }

        $process->setFinishedAt(new DateTime());

        $response->setStatus(200);
        $response->setMessage('Процесс импорта успешно завершен');
        $response->setPayload([
            'progress_html' => $this->renderProgressHtml($process),
            'report' => $this->getReport($process),
        ]);

        ProcessRepository::save($process);

        return $response;
    }

    protected function getProcess(HttpRequest $request)
    {
        $processId = $request->get('pid');
        if (!$processId) {
            throw new \LogicException('Не указан идентификатор процесса');
        }

        return ProcessRepository::findOneById($processId);
    }

    protected function ajaxGenerationPreview(HttpRequest $request)
    {
        $template = $request->get('template');

        $response = new JsonResponse();

        if (strlen($template) === 0) {
            $response->setStatus(500);
            $response->setMessage('Указан пустой шаблон');
        } else {

            $generator = $this->getSequenceGenerator(
                $this->getSequenceTemplate($template)
            );

            $preview = $generator->generateSeveral(20);

            $response->setStatus(200);
            $response->setPayload([
                'preview' => $preview,
            ]);
        }

        return $response;
    }

    protected function renderProgressHtml(Process $process)
    {
        $message = 'Сгенерировано #PROGRESS_VALUE# из #PROGRESS_TOTAL# (#PROGRESS_PERCENT#)';
        if ($process->getProcessedCount() == 0) {
            $message = 'Инициализация ...';
        } elseif ($process->getProgressPercentage() === 100) {
            $message = 'Процесс успешно завершен!';
        }

        $progressMessage = new \CAdminMessage([
            'PROGRESS_TOTAL' => $process->getSettings()->getCount(),
            'PROGRESS_VALUE' => $process->getProcessedCount(),
            'PROGRESS_TEMPLATE' => $message
        ]);

        return $progressMessage->_getProgressHtml();
    }

    protected function getReport(Process $process)
    {
        $startedAt = $process->getStartedAt();
        $startedAt = $startedAt === null ? : $startedAt->format('d.m.Y H:i:s');

        $finishedAt = $process->getFinishedAt();
        $finishedAt = $finishedAt === null ? : $finishedAt->format('d.m.Y H:i:s');
        return [
            [
                'code' => 'started_at',
                'name' => 'Дата начала',
                'value' => $startedAt,
            ],
            [
                'code' => 'finished_at',
                'name' => 'Дата окончания',
                'value' => $finishedAt,
            ],
            [
                'code' => 'count',
                'name' => 'Количество',
                'value' => $process->getProcessedCount(),
            ]
        ];
    }

    protected function getSequenceTemplate($templateString)
    {
        $template = new SequenceTemplate();
        $template->setTemplate($templateString);
        $template->addPlaceholder('@', new RussianLettersCollection());
        $template->addPlaceholder('$', new EnglishLettersCollection());
        $template->addPlaceholder('#', new DigitsCollection());

        return $template;
    }

    protected function getSequenceGenerator(SequenceTemplateInterface $template)
    {
        $generator = new SequenceGenerator();
        $generator->setTemplate($template);
        return $generator;
    }

    protected function getCouponGenerator(SequenceGenerator $generator, Process $progress)
    {
        $couponGenerator = new CouponGenerator($generator, $progress);
        return $couponGenerator;
    }
}
