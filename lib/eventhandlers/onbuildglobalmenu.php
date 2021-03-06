<?php

namespace Maximaster\Coupanda\EventHandlers;

class OnBuildGlobalMenu
{
    private static function getMenuItem()
    {
        return [
            'text' => 'Генератор купонов',
            'url' => '/bitrix/admin/maximaster.coupanda_generator.php',
            'more_url' => [],
            //'page_icon' => 'maximaster_coupanda',
            //'icon' => 'maximaster_coupanda',F
            'title' => 'Генератор купонов',
            'items_id' => 'maximaster_coupanda_generator'
        ];
    }

    public static function addGeneratorToMenu(&$globalMenu, &$moduleMenu)
    {
        global $APPLICATION;
        if ($APPLICATION->GetGroupRight('maximaster.coupanda') < 'W') {
            return;
        }

        $rootMenu = null;
        //Сначала найдем пункт с маркетингом
        foreach ($moduleMenu as &$rootMenuItem) {
            if ($rootMenuItem['items_id'] == 'menu_sale_discounts') {
                $rootMenu = &$rootMenuItem;
                break;
            }
        }

        if ($rootMenu) {
            $newMenu = [];
            foreach ($rootMenu['items'] as $key => &$innerMenuItem) {
                $newMenu[] = $innerMenuItem;
                if (strpos($innerMenuItem['url'], 'sale_discount_coupons.php') !== false) {
                    $newMenu[] = static::getMenuItem();
                }
            }

            $rootMenu['items'] = $newMenu;
        }
    }
}
