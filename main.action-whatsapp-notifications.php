<?php

require_once './Chat-API/src/whatsprot.class.php';

class ActionWhatsapp extends ActionNotification
{
    public static function Init()
    {
        $aParams = array
        (
            "category" => "core/cmdb,application",
            "key_type" => "autoincrement",
            "name_attcode" => "name",
            "state_attcode" => "",
            "reconc_keys" => array('name'),
            "db_table" => "priv_action_whatsapp",
            "db_key_field" => "id",
            "db_finalclass_field" => "",
            "display_template" => "",
        );
        MetaModel::Init_Params($aParams);
        MetaModel::Init_InheritAttributes();

        MetaModel::Init_AddAttribute(new AttributeString("test_recipient", array("allowed_values"=>null, "sql"=>"test_recipient", "default_value"=>"", "is_null_allowed"=>true, "depends_on"=>array())));
        MetaModel::Init_AddAttribute(new AttributeOQL("to", array("allowed_values"=>null, "sql"=>"to", "default_value"=>null, "is_null_allowed"=>true, "depends_on"=>array())));
        MetaModel::Init_AddAttribute(new AttributeTemplateText("body", array("allowed_values"=>null, "sql"=>"body", "default_value"=>null, "is_null_allowed"=>false, "depends_on"=>array())));
        // Display lists
        MetaModel::Init_SetZListItems('details', array('name', 'description', 'status', 'test_recipient', 'to', 'body', 'trigger_list'));
        MetaModel::Init_SetZListItems('list', array('name', 'status', 'to'));
        // Search criteria
        MetaModel::Init_SetZListItems('standard_search', array('name','description', 'status'));
//		MetaModel::Init_SetZListItems('advanced_search', array('name'));
    }

    // count the recipients found
    protected $m_iRecipients;

    // Errors management : not that simple because we need that function to be
    // executed in the background, while making sure that any issue would be reported clearly
    protected $m_aMailErrors; //array of strings explaining the issue

    // returns a the list of emails as a string, or a detailed error description
    protected function FindRecipients($sRecipAttCode, $aArgs)
    {
        $sOQL = $this->Get($sRecipAttCode);
        if (strlen($sOQL) == '') return '';

        try
        {
            $oSearch = DBObjectSearch::FromOQL($sOQL);
            $oSearch->AllowAllData();
        }
        catch (OQLException $e)
        {
            $this->m_aMailErrors[] = "query syntax error for recipient '$sRecipAttCode'";
            return $e->getMessage();
        }

        $sClass = $oSearch->GetClass();
        // Determine the email attribute (the first one will be our choice)
        foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef)
        {
            if ($oAttDef instanceof AttributeEmailAddress)
            {
                $sEmailAttCode = $sAttCode;
                // we've got one, exit the loop
                break;
            }
        }
        if (!isset($sEmailAttCode))
        {
            $this->m_aMailErrors[] = "wrong target for recipient '$sRecipAttCode'";
            return "The objects of the class '$sClass' do not have any email attribute";
        }

        $oSet = new DBObjectSet($oSearch, array() /* order */, $aArgs);
        $aRecipients = array();
        while ($oObj = $oSet->Fetch())
        {
            $sAddress = trim($oObj->Get($sEmailAttCode));
            if (strlen($sAddress) > 0)
            {
                $aRecipients[] = $sAddress;
                $this->m_iRecipients++;
            }
        }
        return implode(', ', $aRecipients);
    }
    
    public function DoExecute($oTrigger, $aContextArgs)
    {
        if (MetaModel::IsLogEnabledNotification())
        {
            $oLog = new EventNotificationEmail();
            if ($this->IsBeingTested())
            {
                $oLog->Set('message', 'TEST - Notification sent ('.$this->Get('test_recipient').')');
            }
            else
            {
                $oLog->Set('message', 'Notification pending');
            }
            $oLog->Set('userinfo', UserRights::GetUser());
            $oLog->Set('trigger_id', $oTrigger->GetKey());
            $oLog->Set('action_id', $this->GetKey());
            $oLog->Set('object_id', $aContextArgs['this->object()']->GetKey());
            // Must be inserted now so that it gets a valid id that will make the link
            // between an eventual asynchronous task (queued) and the log
            $oLog->DBInsertNoReload();
        }
        else
        {
            $oLog = null;
        }

        try
        {
            $sRes = $this->_DoExecute($oTrigger, $aContextArgs, $oLog);

            if ($this->IsBeingTested())
            {
                $sPrefix = 'TEST ('.$this->Get('test_recipient').') - ';
            }
            else
            {
                $sPrefix = '';
            }
            $oLog->Set('message', $sPrefix.$sRes);

        }
        catch (Exception $e)
        {
            if ($oLog)
            {
                $oLog->Set('message', 'Error: '.$e->getMessage());
            }
        }
        if ($oLog)
        {
            $oLog->DBUpdate();
        }
    }

    protected function _DoExecute($oTrigger, $aContextArgs, &$oLog)
    {
        $sPreviousUrlMaker = ApplicationContext::SetUrlMakerClass();
        try
        {
            $this->m_iRecipients = 0;
            $this->m_aMailErrors = array();
            $bRes = false; // until we do succeed in sending the email

            // Determine recicipients
            $sTo = $this->FindRecipients('to', $aContextArgs);
            $sBody = MetaModel::ApplyParams($this->Get('body'), $aContextArgs);

            $oObj = $aContextArgs['this->object()'];
        }
        catch(Exception $e)
        {
            ApplicationContext::SetUrlMakerClass($sPreviousUrlMaker);
            throw $e;
        }
        ApplicationContext::SetUrlMakerClass($sPreviousUrlMaker);

        if (!is_null($oLog))
        {
            // Note: we have to secure this because those values are calculated
            // inside the try statement, and we would like to keep track of as
            // many data as we could while some variables may still be undefined
            if (isset($sTo))       $oLog->Set('to', $sTo);
            if (isset($sBody))     $oLog->Set('body', $sBody);
        }

        // Create a instance of WhastPort.
        $oWA = new WhatsProt($username, $nickname, $debug);

        $oWA->connect(); // Connect to WhatsApp network
        $oWA->loginWithPassword($password); // logging in with the password we got!

        $target = '79263646956'; // The number of the person you are sending the message
        $message = 'Ты тоже это слышишь?';

        $oWA->loginWithPassword($password);
        $oWA->sendGetServerProperties();
        $oWA->sendClientConfig();
        $sync = [$target];
        $oWA->sendSync($sync);
        $oWA->pollMessage();
        $oWA->sendMessage($target , $message);


        $oEmail = new EMail();

        if ($this->IsBeingTested())
        {
            $oEmail->SetSubject('TEST['.$sSubject.']');
            $sTestBody = $sBody;
            $sTestBody .= "<div style=\"border: dashed;\">\n";
            $sTestBody .= "<h1>Testing email notification ".$this->GetHyperlink()."</h1>\n";
            $sTestBody .= "<p>The email should be sent with the following properties\n";
            $sTestBody .= "<ul>\n";
            $sTestBody .= "<li>TO: $sTo</li>\n";
            $sTestBody .= "<li>CC: $sCC</li>\n";
            $sTestBody .= "<li>BCC: $sBCC</li>\n";
            $sTestBody .= "<li>From: $sFrom</li>\n";
            $sTestBody .= "<li>Reply-To: $sReplyTo</li>\n";
            $sTestBody .= "<li>References: $sReference</li>\n";
            $sTestBody .= "</ul>\n";
            $sTestBody .= "</p>\n";
            $sTestBody .= "</div>\n";
            $oEmail->SetBody($sTestBody);
            $oEmail->SetRecipientTO($this->Get('test_recipient'));
            $oEmail->SetRecipientFrom($this->Get('test_recipient'));
            $oEmail->SetReferences($sReference);
            $oEmail->SetMessageId($sMessageId);
        }
        else
        {
            $oEmail->SetSubject($sSubject);
            $oEmail->SetBody($sBody);
            $oEmail->SetRecipientTO($sTo);
            $oEmail->SetRecipientCC($sCC);
            $oEmail->SetRecipientBCC($sBCC);
            $oEmail->SetRecipientFrom($sFrom);
            $oEmail->SetRecipientReplyTo($sReplyTo);
            $oEmail->SetReferences($sReference);
            $oEmail->SetMessageId($sMessageId);
        }

        if (empty($this->m_aMailErrors))
        {
            if ($this->m_iRecipients == 0)
            {
                return 'No recipient';
            }
            else
            {
                $iRes = $oEmail->Send($aErrors, false, $oLog); // allow asynchronous mode
                switch ($iRes)
                {
                    case EMAIL_SEND_OK:
                        return "Sent";

                    case EMAIL_SEND_PENDING:
                        return "Pending";

                    case EMAIL_SEND_ERROR:
                        return "Errors: ".implode(', ', $aErrors);
                }
            }
        }
        else
        {
            if (is_array($this->m_aMailErrors) && count($this->m_aMailErrors) > 0)
            {
                $sError = implode(', ', $this->m_aMailErrors);
            }
            else
            {
                $sError = 'Unknown reason';
            }
            return 'Notification was not sent: '.$sError;
        }
    }
}
