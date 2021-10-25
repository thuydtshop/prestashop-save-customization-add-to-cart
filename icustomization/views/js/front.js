/**
* 2007-2021 PrestaShop
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
*  @copyright 2007-2021 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*
* Don't forget to prefix your containers with your own identifier
* to avoid any conflicts with others containers.
*/
var icustomization = {
	init: function() {
		$('button[name="submitCustomizedData"]').css('display', 'none');
		$('#add-to-cart-or-refresh button.add-to-cart').prop('disabled', false);

		$('#add-to-cart-or-refresh button.add-to-cart').on('click', icustomization.save);
	},
	save: function() {
		if (
			$('.product-customization input').length && 
			$('.product-customization input').prop('required') === true &&
			$('.product-customization input').val() == ''
		) {
			alert(icustomization_message_empty);
			return false;
		} 
		if (
			$('.product-customization textarea').length && 
			$('.product-customization textarea').prop('required') === true &&
			$('.product-customization textarea').val() == ''
		) {
			alert(icustomization_message_empty);
			return false;
		}

		if ($('.product-customization input').length || $('.product-customization textarea').length) {
			$.ajax({
				type: 'post',
				url: icustomization_ajax,
				async: false,
				cache: false,
				data: $('.product-customization form').serialize() + '&id_product=' + $('input[name="id_product"]').val(),
				success: function(response) {
					if (response == null || response == 'null') {
						response = 0;
					}
					$('input[name="id_customization"]').val(response);
					$('#add-to-cart-or-refresh').submit();
				}
			});
		}

		return true;
	}
};

icustomization.init();
$(document).ready(function() {
	icustomization.init();
});