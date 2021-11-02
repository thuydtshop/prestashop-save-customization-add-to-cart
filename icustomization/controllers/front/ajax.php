<?php 

use PrestaShop\PrestaShop\Adapter\Presenter\Object\ObjectPresenter;
use PrestaShop\PrestaShop\Core\Product\ProductExtraContentFinder;

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

        $objectPresenter = new ObjectPresenter();
        $extraContentFinder = new ProductExtraContentFinder();

        $product_ = $objectPresenter->present($this->product);
        $product_['id_product'] = (int) $this->product->id;
        $product_['out_of_stock'] = (int) $this->product->out_of_stock;
        $product_['new'] = (int) $this->product->new;
        $product_['id_product_attribute'] = $this->getIdProductAttributeByGroupOrRequestOrDefault();
        $product_['minimal_quantity'] = $this->getProductMinimalQuantity($product_);
        $product_['quantity_wanted'] = $this->getRequiredQuantity($product_);
        $product_['extraContent'] = $extraContentFinder->addParams(['product' => $this->product])->present();

        $product_['ecotax'] = Tools::convertPrice($this->getProductEcotax($product_), $this->context->currency, true, $this->context);

        $product_full = Product::getProductProperties($this->context->language->id, $product_, $this->context);
        $this->addProductCustomizationData($product_full);

        $this->context->cart->getProducts(true); // fixed have to refresh shopping cart to show customization

        CartRule::autoRemoveFromCart();
        CartRule::autoAddToCart();

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

    protected function getRequiredQuantity($product)
    {
        $requiredQuantity = (int) Tools::getValue('quantity_wanted', $this->getProductMinimalQuantity($product));
        if ($requiredQuantity < $product['minimal_quantity']) {
            $requiredQuantity = $product['minimal_quantity'];
        }

        return $requiredQuantity;
    }

    protected function getProductMinimalQuantity($product)
    {
        $minimal_quantity = 1;

        if ($product['id_product_attribute']) {
            $combination = $this->findProductCombinationById($product['id_product_attribute']);
            if ($combination['minimal_quantity']) {
                $minimal_quantity = $combination['minimal_quantity'];
            }
        } else {
            $minimal_quantity = $this->product->minimal_quantity;
        }

        return $minimal_quantity;
    }

    protected function findProductCombinationById($combinationId)
    {
        $combinations = $this->product->getAttributesGroups($this->context->language->id, $combinationId);

        if ($combinations === false || !is_array($combinations) || empty($combinations)) {
            return null;
        }

        return reset($combinations);
    }

    protected function getIdProductAttributeByGroupOrRequestOrDefault()
    {
        $idProductAttribute = $this->getIdProductAttributeByGroup();
        if (null === $idProductAttribute) {
            $idProductAttribute = (int) Tools::getValue('id_product_attribute');
        }

        if (0 === $idProductAttribute) {
            $idProductAttribute = (int) Product::getDefaultAttribute($this->product->id);
        }

        return $this->tryToGetAvailableIdProductAttribute($idProductAttribute);
    }

    protected function getIdProductAttributeByGroup()
    {
        $groups = Tools::getValue('group');
        if (empty($groups)) {
            return null;
        }

        return (int) Product::getIdProductAttributeByIdAttributes(
            $this->product->id,
            $groups,
            true
        );
    }

    protected function tryToGetAvailableIdProductAttribute($checkedIdProductAttribute)
    {
        if (!Configuration::get('PS_DISP_UNAVAILABLE_ATTR')) {
            $productCombinations = $this->product->getAttributeCombinations();
            if (!Product::isAvailableWhenOutOfStock($this->product->out_of_stock)) {
                $availableProductAttributes = array_filter(
                    $productCombinations,
                    function ($elem) {
                        return $elem['quantity'] > 0;
                    }
                );
            } else {
                $availableProductAttributes = $productCombinations;
            }

            $availableProductAttribute = array_filter(
                $availableProductAttributes,
                function ($elem) use ($checkedIdProductAttribute) {
                    return $elem['id_product_attribute'] == $checkedIdProductAttribute;
                }
            );

            if (empty($availableProductAttribute) && count($availableProductAttributes)) {
                // if selected combination is NOT available ($availableProductAttribute) but they are other alternatives ($availableProductAttributes), then we'll try to get the closest.
                if (!Product::isAvailableWhenOutOfStock($this->product->out_of_stock)) {
                    // first lets get information of the selected combination.
                    $checkProductAttribute = array_filter(
                        $productCombinations,
                        function ($elem) use ($checkedIdProductAttribute) {
                            return $elem['id_product_attribute'] == $checkedIdProductAttribute || (!$checkedIdProductAttribute && $elem['default_on']);
                        }
                    );
                    if (count($checkProductAttribute)) {
                        // now lets find other combinations for the selected attributes.
                        $alternativeProductAttribute = [];
                        foreach ($checkProductAttribute as $key => $attribute) {
                            $alternativeAttribute = array_filter(
                                $availableProductAttributes,
                                function ($elem) use ($attribute) {
                                    return $elem['id_attribute'] == $attribute['id_attribute'] && !$elem['is_color_group'];
                                }
                            );
                            foreach ($alternativeAttribute as $key => $value) {
                                $alternativeProductAttribute[$key] = $value;
                            }
                        }

                        if (count($alternativeProductAttribute)) {
                            // if alternative combination is found, order the list by quantity to use the one with more stock.
                            usort($alternativeProductAttribute, function ($a, $b) {
                                if ($a['quantity'] == $b['quantity']) {
                                    return 0;
                                }

                                return ($a['quantity'] > $b['quantity']) ? -1 : 1;
                            });

                            return (int) array_shift($alternativeProductAttribute)['id_product_attribute'];
                        }
                    }
                }

                return (int) array_shift($availableProductAttributes)['id_product_attribute'];
            }
        }

        return $checkedIdProductAttribute;
    }

    protected function getProductEcotax(array $product): float
    {
        $ecotax = $product['ecotax'];

        if ($product['id_product_attribute']) {
            $combination = $this->findProductCombinationById($product['id_product_attribute']);
            if (isset($combination['ecotax']) && $combination['ecotax'] > 0) {
                $ecotax = $combination['ecotax'];
            }
        }
        if ($ecotax) {
            // Try to get price display from already assigned smarty variable for better performance
            $priceDisplay = $this->context->smarty->getTemplateVars('priceDisplay');
            if (null === $priceDisplay) {
                $priceDisplay = Product::getTaxCalculationMethod((int) $this->context->cookie->id_customer);
            }

            $useTax = $priceDisplay == 0;
            if ($useTax) {
                $ecotax *= (1 + Tax::getProductEcotaxRate() / 100);
            }
        }

        return (float) $ecotax;
    }
}