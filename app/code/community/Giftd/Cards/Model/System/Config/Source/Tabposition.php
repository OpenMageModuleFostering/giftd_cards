<?php

class Giftd_Cards_Model_System_Config_Source_Tabposition
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return array(
            array('value' => 'top', 'label'=>Mage::helper('adminhtml')->__('Top ')),
            array('value' => 'left', 'label'=>Mage::helper('adminhtml')->__('Left')),
            array('value' => 'bottom', 'label'=>Mage::helper('adminhtml')->__('Bottom ')),
        );
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return array(
            'top' => Mage::helper('adminhtml')->__('Top'),
            'left' => Mage::helper('adminhtml')->__('Left'),
            'bottom' => Mage::helper('adminhtml')->__('Bottom'),
        );
    }
}