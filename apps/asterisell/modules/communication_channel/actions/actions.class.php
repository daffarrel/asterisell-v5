<?php


/**
 * communication_channel actions.
 *
 * @package    asterisell
 * @subpackage communication_channel
 * @author     Your name here
 * @version    SVN: $Id: actions.class.php 12474 2008-10-31 10:41:27Z fabien $
 */
class communication_channelActions extends autoCommunication_channelActions
{

    /**
     * @param Criteria $c
     */
    protected function addSortCriteria($c)
    {
        // force a sort on ID for viewing all the calls in the LIMIT pagination

        parent::addSortCriteria($c);
        $c->addAscendingOrderByColumn(ArCommunicationChannelTypePeer::ID);
    }
}
