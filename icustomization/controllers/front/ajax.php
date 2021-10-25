<?php 
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
*/

class IcustomizationAjaxModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    protected $product;

    public function postProcess()
    {
    	$id_product = (int) Tools::getValue('id_product');
    	$this->product = new Product($id_product, true, $this->context->language->id, $this->context->shop->id);

    	if (!Validate::isLoadedObject($this->product)) {
    		header('Content-Type: application/json');
        	die(json_encode([]));
    	}

        ///ob_end_clean();

    	// If cart has not been saved, we need to do it so that customization fields can have an id_cart
        // We check that the cookie exists first to avoid ghost carts
        if (!$this->context->cart->id && isset($_COOKIE[$this->context->cookie->getName()])) {
            $this->context->cart->add();
            $this->context->cookie->id_cart = (int) $this->context->cart->id;
        }
        $this->pictureUpload();
        $this->textRecord();

        //$customization_fields = $this->product->getCustomizationFields($this->context->language->id);

        if ($this->product->customizable) {
        	$customization_datas = $this->context->cart->getProductCustomization($this->product->id, null, true);
        }

        $id_customization = empty($customization_datas) ? null : $customization_datas[0]['id_customization'];

        ///Cart::resetStaticCache();
        $product_full = Product::getProductProperties($this->context->language->id, $this->product, $this->context);
        $this->addProductCustomizationData();

        echo $id_customization;
        exit;
    }

    protected function pictureUpload()
    {
        if (!$field_ids = $this->product->getCustomizationFieldIds()) {
            return false;
        }
        $authorized_file_fields = [];
        foreach ($field_ids as $field_id) {
            if ($field_id['type'] == Product::CUSTOMIZE_FILE) {
                $authorized_file_fields[(int) $field_id['id_customization_field']] = 'file' . (int) $field_id['id_customization_field'];
            }
        }
        $indexes = array_flip($authorized_file_fields);
        foreach ($_FILES as $field_name => $file) {
            if (in_array($field_name, $authorized_file_fields) && isset($file['tmp_name']) && !empty($file['tmp_name'])) {
                $file_name = md5(uniqid(mt_rand(0, mt_getrandmax()), true));
                if ($error = ImageManager::validateUpload($file, (int) Configuration::get('PS_PRODUCT_PICTURE_MAX_SIZE'))) {
                    $this->errors[] = $error;
                }

                $product_picture_width = (int) Configuration::get('PS_PRODUCT_PICTURE_WIDTH');
                $product_picture_height = (int) Configuration::get('PS_PRODUCT_PICTURE_HEIGHT');
                $tmp_name = tempnam(_PS_TMP_IMG_DIR_, 'PS');
                if ($error || (!$tmp_name || !move_uploaded_file($file['tmp_name'], $tmp_name))) {
                    return false;
                }
                
                if (!ImageManager::resize($tmp_name, _PS_UPLOAD_DIR_ . $file_name)) {
                    $this->errors[] = $this->trans('An error occurred during the image upload process.', [], 'Shop.Notifications.Error');
                } elseif (!ImageManager::resize($tmp_name, _PS_UPLOAD_DIR_ . $file_name . '_small', $product_picture_width, $product_picture_height)) {
                    $this->errors[] = $this->trans('An error occurred during the image upload process.', [], 'Shop.Notifications.Error');
                } else {
                    $this->context->cart->addPictureToProduct($this->product->id, $indexes[$field_name], Product::CUSTOMIZE_FILE, $file_name);
                }
                unlink($tmp_name);
            }
        }

        return true;
    }

    protected function textRecord()
    {
        if (!$field_ids = $this->product->getCustomizationFieldIds()) {
            return false;
        }

        $authorized_text_fields = [];
        foreach ($field_ids as $field_id) {
            if ($field_id['type'] == Product::CUSTOMIZE_TEXTFIELD) {
                $authorized_text_fields[(int) $field_id['id_customization_field']] = 'textField' . (int) $field_id['id_customization_field'];
            }
        }

        $indexes = array_flip($authorized_text_fields);
        foreach ($_POST as $field_name => $value) {
            if (in_array($field_name, $authorized_text_fields) && $value != '') {
                if (!Validate::isMessage($value)) {
                    $this->errors[] = $this->trans('Invalid message', [], 'Shop.Notifications.Error');
                } else {
                    $this->context->cart->addTextFieldToProduct($this->product->id, $indexes[$field_name], Product::CUSTOMIZE_TEXTFIELD, $value);
                }
            } elseif (in_array($field_name, $authorized_text_fields) && $value == '') {
                $this->context->cart->deleteCustomizationToProduct((int) $this->product->id, $indexes[$field_name]);
            }
        }
    }

    protected function addProductCustomizationData(array $product_full)
    {
        if ($product_full['customizable']) {
            $customizationData = [
                'fields' => [],
            ];

            $customized_data = [];

            $already_customized = $this->context->cart->getProductCustomization(
                $product_full['id_product'],
                null,
                true
            );

            $id_customization = 0;
            foreach ($already_customized as $customization) {
                $id_customization = $customization['id_customization'];
                $customized_data[$customization['index']] = $customization;
            }

            $customization_fields = $this->product->getCustomizationFields($this->context->language->id);
            if (is_array($customization_fields)) {
                foreach ($customization_fields as $customization_field) {
                    // 'id_customization_field' maps to what is called 'index'
                    // in what Product::getProductCustomization() returns
                    $key = $customization_field['id_customization_field'];

                    $field['label'] = $customization_field['name'];
                    $field['id_customization_field'] = $customization_field['id_customization_field'];
                    $field['required'] = $customization_field['required'];

                    switch ($customization_field['type']) {
                        case Product::CUSTOMIZE_FILE:
                            $field['type'] = 'image';
                            $field['image'] = null;
                            $field['input_name'] = 'file' . $customization_field['id_customization_field'];

                            break;
                        case Product::CUSTOMIZE_TEXTFIELD:
                            $field['type'] = 'text';
                            $field['text'] = '';
                            $field['input_name'] = 'textField' . $customization_field['id_customization_field'];

                            break;
                        default:
                            $field['type'] = null;
                    }

                    if (array_key_exists($key, $customized_data)) {
                        $data = $customized_data[$key];
                        $field['is_customized'] = true;
                        switch ($customization_field['type']) {
                            case Product::CUSTOMIZE_FILE:
                                $imageRetriever = new ImageRetriever($this->context->link);
                                $field['image'] = $imageRetriever->getCustomizationImage(
                                    $data['value']
                                );
                                $field['remove_image_url'] = $this->context->link->getProductDeletePictureLink(
                                    $product_full,
                                    $customization_field['id_customization_field']
                                );

                                break;
                            case Product::CUSTOMIZE_TEXTFIELD:
                                $field['text'] = $data['value'];

                                break;
                        }
                    } else {
                        $field['is_customized'] = false;
                    }

                    $customizationData['fields'][] = $field;
                }
            }
            $product_full['customizations'] = $customizationData;
            $product_full['id_customization'] = $id_customization;
            $product_full['is_customizable'] = true;
        } else {
            $product_full['customizations'] = [
                'fields' => [],
            ];
            $product_full['id_customization'] = 0;
            $product_full['is_customizable'] = false;
        }

        return $product_full;
    }
}