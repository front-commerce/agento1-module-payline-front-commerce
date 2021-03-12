<?php

class FrontCommerce_Payline_Model_Api2_Payline_Token_Rest_Guest_V1
extends FrontCommerce_Integration_Model_Api2_Abstract
{
  protected function _retrieve()
  {
    throw new \RuntimeException('Guest checkout is not yet supported');
  }
}
