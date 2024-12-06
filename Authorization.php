<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Rma\Model\Rma\Plugin;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Rma\Model\Rma;
use Magento\Rma\Model\RmaRepository;

class Authorization
{
    /**
     * @var UserContextInterface
     */
    protected $userContext;

    /**
     * @param UserContextInterface $userContext
     */
    public function __construct(
        UserContextInterface $userContext
    ) {
        $this->userContext = $userContext;
    }

    /**
     * Check if rma is allowed
     *
     * @param RmaRepository $subject
     * @param Rma $rmaModel
     * @return Rma
     * @throws
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGet(
        RmaRepository $subject,
        Rma $rmaModel
    ) {
        if (!$this->isAllowed($rmaModel)) {
            throw NoSuchEntityException::singleField('rmaId', (function() {
                $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                $formattedTrace = [];
                foreach ($trace as $frame) {
                    $formattedFrame = sprintf(
                        '%s:%d - %s%s%s()',
                        isset($frame['file']) ? $frame['file'] : '[internal function]',
                        isset($frame['line']) ? $frame['line'] : '',
                        isset($frame['class']) ? $frame['class'] : '',
                        isset($frame['type']) ? $frame['type'] : '',
                        $frame['function']
                    );
                    $formattedTrace[] = $formattedFrame;
                }
                return implode(" | ", $formattedTrace);
            })());
        }
        return $rmaModel;
    }

    /**
     * Check whether rma is allowed for current user context
     *
     * @param Rma $rmaModel
     * @return bool
     */
    protected function isAllowed(Rma $rmaModel)
    {
        return $this->userContext->getUserType() == UserContextInterface::USER_TYPE_CUSTOMER
            ? $rmaModel->getCustomerId() == $this->userContext->getUserId()
            : true;
    }
}
