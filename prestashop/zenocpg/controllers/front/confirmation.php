<?php
/**
 * 2007-2026 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2026 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ZenocpgConfirmationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        if (!isset($_GET['cart_id'])) {
            Tools::redirect('index.php?controller=order');
            return;
        }

        $id_cart = (int) $_GET['cart_id'];
        $cart = new Cart($id_cart);

        if (!Validate::isLoadedObject($cart)) {
            Tools::redirect('index.php?controller=order');
            return;
        }

        $id_customer = $cart->id_customer;
        $customer = new Customer($id_customer);
        $secure_key = $customer->secure_key;
        $id_order = Order::getIdByCartId($id_cart);

        if (!$id_order) {
            Tools::redirect('index.php?controller=order');
            return;
        }

        Tools::redirect(
            'index.php?controller=order-confirmation&id_cart=' . $id_cart
            . '&id_module=' . $this->module->id
            . '&id_order=' . $id_order
            . '&key=' . $secure_key
        );
    }

}
