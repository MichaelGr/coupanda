<?php

namespace Maximaster\Coupanda\Generator\AdminTemplate;

use \Bitrix\Main\Localization\Loc;
\CJSCore::Init('window');
function getHint($id, $hint)
{
    $id = 'hint_' . $id;
    $hint = \CUtil::JSEscape($hint);
    $html = <<<HTML
        <span id="{$id}"></span>
        <script>BX.hint_replace(BX('{$id}'), '{$hint}');</script>
HTML;
    return trim($html);
}
?>
<style>
    .popup-window--coupanda-padded {
        padding: 20px;
        max-width: 80%;
    }

    .popup-window__preview-coupon {
        margin: 5px 10px;
        display: inline-block;
    }
</style>
<?=\BeginNote();?>
Данная страница осуществляет процесс генерации новых купонов. Сначала нужно произвести настройку на вкладке "Настройка генерации",
после подтверждения которых сразу же будет запущен процесс генерации, с которым можно ознакомиться на вкладке "Процесс генерации".
После окончания генерации вся сводная информация о процессе будет доступна во вкладке "Отчет о генерации"
<?=\EndNote();?>
<?
$buttons = [];

$tabs = [
    [
        'DIV' => 'configuration',
        'TAB' => 'Настройка генерации',
        'ICON' => '',
        'TITLE' => 'Настройка генерации'
    ],
    [
        'DIV' => 'progress',
        'TAB' => 'Процесс генерации',
        'ICON' => '',
        'TITLE' => 'Процесс генерации'
    ],
    [
        'DIV' => 'report',
        'TAB' => 'Отчет о генерации',
        'ICON' => '',
        'TITLE' => 'Отчет о генерации'
    ],
];

$tabControl = new \CAdminTabControl('coupanda_generator', $tabs, false, true);
$tabControl->Begin();
$tabControl->BeginNextTab();
include __DIR__ . '/configuration.php';
$tabControl->BeginNextTab();
echo '<div id="js-progress-block"></div>';
$tabControl->BeginNextTab();
echo '<div id="js-report-block"></div>';
$tabControl->End();
