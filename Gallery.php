<?php

/**
 * Magic 360 view block
 *
 */
namespace MagicToolbox\Magic360\Block\Product\View;

use Magento\Framework\Data\Collection;
use MagicToolbox\Magic360\Helper\Data;
use MagicToolbox\Magic360\Model\GalleryFactory;
use MagicToolbox\Magic360\Model\ColumnsFactory;

class Gallery extends \Magento\Catalog\Block\Product\View\Gallery
{
    /**
     * Helper
     *
     * @var \MagicToolbox\Magic360\Helper\Data
     */
    public $magicToolboxHelper = null;

    /**
     * Magic360 module core class
     *
     * @var \MagicToolbox\Magic360\Classes\Magic360ModuleCoreClass
     */
    public $toolObj = null;

    /**
     * Collection factory
     *
     * @var \Magento\Framework\Data\CollectionFactory
     */
    protected $collectionFactory = null;

    /**
     * Model factory
     *
     * @var \MagicToolbox\Magic360\Model\GalleryFactory
     */
    protected $modelGalleryFactory = null;

    /**
     * Model factory
     *
     * @var \MagicToolbox\Magic360\Model\ColumnsFactory
     */
    protected $modelColumnsFactory = null;

    /**
     * Magic360 image helper
     *
     * @var \MagicToolbox\Magic360\Helper\Image
     */
    public $magic360ImageHelper;

    /**
     * Display Magic360 spin as a separate block or inside fotorama gallery
     *
     * @var bool
     */
    protected $standaloneMode = false;

    /**
     * Magic360 spin position
     *
     * @var integer
     */
    protected $spinPosition = 0;

    /**
     * Rendered gallery HTML
     *
     * @var array
     */
    protected $renderedGalleryHtml = [];

    /**
     * ID of the current product
     *
     * @var integer
     */
    protected $currentProductId = null;

    /**
     * Do reload product
     *
     * @var bool
     */
    protected $doReloadProduct = false;

    /**
     * Internal constructor, that is called from real constructor
     *
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $this->magicToolboxHelper = $objectManager->get(\MagicToolbox\Magic360\Helper\Data::class);
        $this->toolObj = $this->magicToolboxHelper->getToolObj();
        $this->collectionFactory = $objectManager->get(\Magento\Framework\Data\CollectionFactory::class);
        $this->modelGalleryFactory = $objectManager->get(\MagicToolbox\Magic360\Model\GalleryFactory::class);
        $this->modelColumnsFactory = $objectManager->get(\MagicToolbox\Magic360\Model\ColumnsFactory::class);
        $this->magic360ImageHelper = $objectManager->get(\MagicToolbox\Magic360\Helper\Image::class);
        $this->standaloneMode = $this->toolObj->params->checkValue('display-spin', 'separately', 'product');
        $this->spinPosition = $this->toolObj->params->getValue('spin-position', 'product') - 1;

        $version = $this->magicToolboxHelper->getMagentoVersion();
        if (version_compare($version, '2.2.5', '<')) {
            $this->doReloadProduct = true;
        }

        //NOTE: for versions 2.2.x (x >=9), 2.3.x (x >=2)
        if (class_exists('\Magento\Catalog\Block\Product\View\GalleryOptions')) {
            $galleryOptions = $objectManager->get(\Magento\Catalog\Block\Product\View\GalleryOptions::class);
            $this->setData('gallery_options', $galleryOptions);
        }

        //NOTE: for versions 2.3.x (x >=2)
        if (version_compare($version, '2.3.2', '>=')) {
            $imageHelper = $objectManager->get(\Magento\Catalog\Helper\Image::class);
            $this->setData('imageHelper', $imageHelper);
        }
    }

    /**
     * Retrieve collection of Magic360 gallery images
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return Magento\Framework\Data\Collection
     */
    public function getGalleryImagesCollection($product = null)
    {
        static $images = [];
        if (is_null($product)) {
            $product = $this->getProduct();
        }
        $id = $product->getId();
        if (!isset($images[$id])) {
            $images[$id] = $this->collectionFactory->create();
            $galleryModel = $this->modelGalleryFactory->create();
            $collection = $galleryModel->getCollection();
            $collection->addFieldToFilter('product_id', $id);
            if ($collection->count()) {
                $_images = $collection->getData();
                $compare = function ($a, $b) {
                    if ($a['position'] == $b['position']) {
                        return 0;
                    }
                    return (int)$a['position'] > (int)$b['position'] ? 1 : -1;
                };
                usort($_images, $compare);

                $makeSquareImages = $this->toolObj->params->checkValue('square-images', 'Yes');

                foreach ($_images as &$image) {
                    if (!$this->magic360ImageHelper->fileExists($image['file'])) {
                        continue;
                    }
                    unset($image['product_id']);
                    $image['large_image_url'] = $this->magic360ImageHelper
                        ->init($product, 'product_page_image_large', ['width' => null, 'height' => null])
                        ->setImageFile($image['file'])
                        ->getUrl();

                    $originalSizeArray = $this->magic360ImageHelper->getImageSizeArray();

                    if ($makeSquareImages) {
                        $bigImageSize = ($originalSizeArray[0] > $originalSizeArray[1]) ? $originalSizeArray[0] : $originalSizeArray[1];
                        $image['large_image_url'] = $this->magic360ImageHelper
                            ->init($product, 'product_page_image_large')
                            ->setImageFile($image['file'])
                            ->keepFrame(true)
                            ->resize($bigImageSize)
                            ->getUrl();
                    }

                    list($w, $h) = $this->magicToolboxHelper->magicToolboxGetSizes('thumb', $originalSizeArray);
                    $this->magic360ImageHelper
                        ->init($product, 'product_page_image_medium', ['width' => $w, 'height' => $h])
                        ->setImageFile($image['file']);
                    if ($makeSquareImages) {
                        $this->magic360ImageHelper->keepFrame(true);
                    }
                    $image['medium_image_url'] = $this->magic360ImageHelper->getUrl();

                    $images[$id]->addItem(new \Magento\Framework\DataObject($image));
                }
            }
        }
        return $images[$id];
    }

    /**
     * Retrieve columns param
     *
     * @param integer $id
     * @return integer
     */
    public function getColumns($id = null)
    {
        static $columns = [];
        if (is_null($id)) {
            $id = $this->getProduct()->getId();
        }
        if (!isset($columns[$id])) {
            $columnsModel = $this->modelColumnsFactory->create();
            $columnsModel->load($id);
            $_columns = $columnsModel->getData('columns');
            $columns[$id] = $_columns ? $_columns : 0;
        }
        return $columns[$id];
    }

    /**
     * Retrieve additional gallery block
     *
     * @return mixed
     */
    public function getAdditionalBlock()
    {
        $data = $this->_coreRegistry->registry('magictoolbox');
        if ($data && !empty($data['additional-block-name'])) {
            return $data['blocks'][$data['additional-block-name']];
        }
        return null;
    }

    /**
     * Retrieve original gallery block
     *
     * @return mixed
     */
    public function getOriginalBlock()
    {
        $data = $this->_coreRegistry->registry('magictoolbox');
        return is_null($data) ? null : $data['blocks']['product.info.media.image'];
    }

    /**
     * Retrieve another gallery block
     *
     * @return mixed
     */
    public function getAnotherBlock()
    {
        $data = $this->_coreRegistry->registry('magictoolbox');
        if ($data) {
            if (!empty($data['additional-block-name'])) {
                return $data['blocks'][$data['additional-block-name']];
            }
            $skip = true;
            foreach ($data['blocks'] as $name => $block) {
                if ($name == 'product.info.media.magic360') {
                    $skip = false;
                    continue;
                }
                if ($skip) {
                    continue;
                }
                if ($block) {
                    return $block;
                }
            }
        }
        return null;
    }

    /**
     * Check for installed modules, which can operate in cooperative mode
     *
     * @return bool
     */
    public function isCooperativeModeAllowed()
    {
        $data = $this->_coreRegistry->registry('magictoolbox');
        return is_null($data) ? false : $data['cooperative-mode'];
    }

    /**
     * Get thumb switcher initialization attribute
     *
     * @param integer $id
     * @return string
     */
    public function getThumbSwitcherInitAttribute($id = null)
    {
        static $html = null;
        if ($html === null) {
            if (is_null($id)) {
                $id = $this->currentProductId;
            }
            $data = $this->_coreRegistry->registry('magictoolbox');
            $block = $data['blocks'][$data['additional-block-name']];
            $html = $block->getThumbSwitcherInitAttribute($id);
        }
        return $html;
    }

    /**
     * Before rendering html, but after trying to load cache
     *
     * @return $this
     */
    protected function _beforeToHtml()
    {
        $this->renderGalleryHtml();
        return parent::_beforeToHtml();
    }

    /**
     * Get rendered HTML
     *
     * @param integer $id
     * @return string
     */
    public function getRenderedHtml($id = null)
    {
        if (is_null($id)) {
            $id = $this->getProduct()->getId();
        }
        return isset($this->renderedGalleryHtml[$id]) ? $this->renderedGalleryHtml[$id] : '';
    }

    /**
     * Render gallery block HTML
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param bool $isAssociatedProduct
     * @param array $data
     * @return $this
     */
    public function renderGalleryHtml($product = null, $isAssociatedProduct = false, $data = [])
    {
        if (is_null($product)) {
            $product = $this->getProduct();
        }
        $this->currentProductId = $id = $product->getId();
        if (!isset($this->renderedGalleryHtml[$id])) {
            $this->toolObj->params->setProfile('product');
            $magic360Data = [];

            $images = $this->getGalleryImagesCollection($product);
            $columns = $this->getColumns($id);
            if ($columns > $images->count()) {
                $columns = $images->count();
            }
            $this->toolObj->params->setValue('columns', $columns);

            $originalBlock = $this->getAnotherBlock();
            $mtAsOriginal = $originalBlock->getData('mt_as_original');

            if (!$images->count()) {
                if ($originalBlock) {
                    if ((strpos($originalBlock->getModuleName(), 'MagicToolbox_') === 0) && !$mtAsOriginal) {
                        $this->renderedGalleryHtml[$id] = $originalBlock->renderGalleryHtml($product, $isAssociatedProduct)->getRenderedHtml($id);
                    } else {
                        //NOTE: Magento_Catalog
                        $this->renderedGalleryHtml[$id] = $isAssociatedProduct ? '' : $originalBlock->toHtml();
                    }
                }
                return $this;
            }

            foreach ($images as $image) {

                $magic360Data[] = [
                    'medium' => $image->getData('medium_image_url'),
                    'img' => $image->getData('large_image_url'),
                ];
            }

            $this->renderedGalleryHtml[$id] = $this->toolObj->getMainTemplate($magic360Data, ['id' => "Magic360-product-{$id}"]);

            if ($this->isCooperativeModeAllowed()) {
                $additionalBlock = $this->getAdditionalBlock();
                $_images = $additionalBlock->getGalleryImagesCollection($product);
                if ($_images->count()) {
                    $magic360Icon = $this->getMagic360IconPath();
                    if ($magic360Icon) {
                        $magic360IconUrl = $this->magic360ImageHelper
                            ->init(null, 'product_page_image_small', ['width' => null, 'height' => null])
                            ->setImageFile($magic360Icon)
                            ->getUrl();
                        $originalSizeArray = $this->magic360ImageHelper->getImageSizeArray();

                        list($w, $h) = $additionalBlock->magicToolboxHelper->magicToolboxGetSizes('selector', $originalSizeArray);
                        $magic360Icon = $this->magic360ImageHelper
                            ->init(null, 'product_page_image_small', ['width' => $w, 'height' => $h])
                            ->setImageFile($magic360Icon)
                            ->getUrl();
                    } else {
                        $magic360Icon = $this->_imageHelper->getDefaultPlaceholderUrl('thumbnail');
                    }

                    $this->renderedGalleryHtml[$id] = $additionalBlock->renderGalleryHtml(
                        $product,
                        $isAssociatedProduct,
                        [
                            'magic360-icon' => $magic360Icon,
                            'magic360-position' => $this->spinPosition,
                            'magic360-html' => $this->renderedGalleryHtml[$id]
                        ]
                    )->getRenderedHtml($id);
                } else {
                    $this->renderedGalleryHtml[$id] = '<div class="MagicToolboxContainer"'.$this->getThumbSwitcherInitAttribute().'>'.$this->renderedGalleryHtml[$id].'</div>';
                }
                return $this;
            }

            $this->renderedGalleryHtml[$id] = '<div class="MagicToolboxContainer">'.$this->renderedGalleryHtml[$id].'</div>';

            //NOTE: check for the case where some module removes the original block, replacing it with its own
            if ($originalBlock) {
                //NOTE: use original gallery (content that was generated before will be used there)
                if (!$isAssociatedProduct && ((strpos($originalBlock->getModuleName(), 'MagicToolbox_') === false) || $mtAsOriginal)) {
                    $this->renderedGalleryHtml[$id] = $this->getDefaultGalleryHtml($mtAsOriginal);
                }
            }
        }
        return $this;
    }

    /**
     * Get default gallery HTML
     *
     * @param bool $mtAsOriginal
     * @return string
     */
    public function getDefaultGalleryHtml($mtAsOriginal = false)
    {
        static $html = null;
        if (is_null($html)) {
            $moduleName = $this->getModuleName();
            $template = $this->getTemplate();

            $this->setData('module_name', 'Magento_Catalog');
            if ($mtAsOriginal) {
                $this->setTemplate('MagicToolbox_Magic360::product/view/gallery_original.phtml');
            } else {
                $this->setTemplate('Magento_Catalog::product/view/gallery.phtml');
            }

            $html = $this->toHtml();

            $this->setData('module_name', $moduleName);
            $this->setTemplate($template);
        }
        return $html;
    }

    /**
     * Get spin HTML
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string
     */
    public function getSpinHtml($product = null)
    {
        static $html = [];

        if (is_null($product)) {
            $product = $this->getProduct();
        }
        $id = $product->getId();

        if (!isset($html[$id])) {
            $html[$id] = '';

            $this->toolObj->params->setProfile('product');
            $images = $this->getGalleryImagesCollection($product);

            if (!$images->count()) {
                return '';
            }

            $columns = $this->getColumns($id);
            if ($columns > $images->count()) {
                $columns = $images->count();
            }
            $this->toolObj->params->setValue('columns', $columns);

            $magic360Data = [];
            foreach ($images as $image) {
                $magic360Data[] = [
                    'medium' => $image->getData('medium_image_url'),
                    'img' => $image->getData('large_image_url'),
                ];
            }

            $html[$id] = $this->toolObj->getMainTemplate($magic360Data, ['id' => "Magic360-product-{$id}"]);
            $html[$id] = '<div class="MagicToolboxContainer">'.$html[$id].'</div>';
        }

        return $html[$id];
    }

    /**
     * Is the effect enabled
     *
     * @return boolean
     */
    public function isEffectEnabled()
    {
        return $this->toolObj->params->checkValue('enable-effect', 'Yes', 'product');
    }

    /**
     * Is Magic360 spin displayed as a separate block
     *
     * @return boolean
     */
    public function isStandaloneMode()
    {
        return $this->standaloneMode;
    }

    /**
     * Retrieve product images in JSON format
     *
     * @return string
     */
    public function getGalleryImagesJson()
    {
        $imagesItems = [];
        $product = $this->getProduct();
        $magic360Slide = null;

        if (!$this->standaloneMode) {
            $images = $this->getGalleryImagesCollection($product);
            if ($images->count()) {
                $magic360Icon = $this->getMagic360IconPath();
                if ($magic360Icon) {
                    $magic360Icon = $this->magic360ImageHelper
                        ->init(null, 'product_page_image_small', ['width' => null, 'height' => null])
                        ->setImageFile($magic360Icon)
                        ->getUrl();
                } else {
                    $magic360Icon = $this->_imageHelper->getDefaultPlaceholderUrl('thumbnail');
                }
                $id = $product->getId();
                $magic360Slide = [
                    'magic360' => 'Magic360-product-'.$id,
                    'thumb' => $magic360Icon,
                    'img' => $magic360Icon,
                    'html' => '<div class="fotorama__select">'.$this->renderedGalleryHtml[$id].'</div>',
                    'caption' => '',
                    'position' => $this->spinPosition,
                    'isMain' => true,
                    'type' => 'magic360',
                    'videoUrl' => null,
                    'fit' => 'none',
                ];
            }
        }

        $productImages = $this->getGalleryImages() ?: [];
        $productMainImage = $product->getImage();
        $productName = $product->getName();
        $isMain = !($magic360Slide);
        $position = 0;
        foreach ($productImages as $image) {
            if ($position == $this->spinPosition) {
                if ($magic360Slide) {
                    $imagesItems[$position] = $magic360Slide;
                    $magic360Slide = null;
                    $position++;
                }
            }
            $imagesItems[$position] = [
                'thumb' => $image->getData('small_image_url'),
                'img' => $image->getData('medium_image_url'),
                'full' => $image->getData('large_image_url'),
                'caption' => ($image->getLabel() ?: $productName),
                'position' => $position,
                'isMain' => ($isMain && ($image->getFile() == $productMainImage)),
                'type' => str_replace('external-', '', $image->getMediaType()),
                'videoUrl' => $image->getVideoUrl(),
            ];
            $position++;
        }

        if ($magic360Slide) {
            $imagesItems[$position] = $magic360Slide;
        }

        if (empty($imagesItems)) {
            $imagesItems[] = [
                'thumb' => $this->_imageHelper->getDefaultPlaceholderUrl('thumbnail'),
                'img' => $this->_imageHelper->getDefaultPlaceholderUrl('image'),
                'full' => $this->_imageHelper->getDefaultPlaceholderUrl('image'),
                'caption' => '',
                'position' => 0,
                'isMain' => true,
                'type' => 'image',
                'videoUrl' => null,
            ];
        }

        return json_encode($imagesItems);
    }

    /**
     * Get Magic360 icon path
     *
     * @return string
     */
    public function getMagic360IconPath()
    {
        static $path = null;
        if (is_null($path)) {
            $this->toolObj->params->setProfile('product');
            $icon = $this->toolObj->params->getValue('icon');
            $hash = hash('sha256', $icon);
            $model = $this->magic360ImageHelper->getModel();
            $mediaDirectory = $model->getMediaDirectory();
            if ($mediaDirectory->isFile('magic360/icon/'.$hash.'/360icon.jpg')) {
                $path = 'icon/'.$hash.'/360icon.jpg';
            } else {
                $rootDirectory = $model->getRootDirectory();
                if ($rootDirectory->isFile($icon)) {
                    $rootDirectory->copyFile($icon, 'magic360/icon/'.$hash.'/360icon.jpg', $mediaDirectory);
                    $path = 'icon/'.$hash.'/360icon.jpg';
                } else {
                    $path = '';
                }
            }
        }
        return $path;
    }
}
