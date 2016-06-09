<?php

require_once MODULESROOT.'action-whatsapp-notifications/Chat-API/src/whatsprot.class.php';

class ActionWhatspp extends ActionNotification
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
        MetaModel::Init_AddAttribute(new AttributeOQL("target", array("allowed_values"=>null, "sql"=>"target", "default_value"=>null, "is_null_allowed"=>true, "depends_on"=>array())));
        MetaModel::Init_AddAttribute(new AttributeTemplateText("message", array("allowed_values"=>null, "sql"=>"message", "default_value"=>null, "is_null_allowed"=>false, "depends_on"=>array())));
        // Display lists
        MetaModel::Init_SetZListItems('details', array('name', 'description', 'status', 'test_recipient', 'target', 'message', 'trigger_list'));
        MetaModel::Init_SetZListItems('list', array('name', 'status', 'target'));
        // Search criteria
        MetaModel::Init_SetZListItems('standard_search', array('name','description', 'status'));
//		MetaModel::Init_SetZListItems('advanced_search', array('name'));
    }

    // count the recipients found
    protected $m_iRecipients;

    // Errors management : not that simple because we need that function to be
    // executed in the background, while making sure that any issue would be reported clearly
    protected $m_aWhatsAppErrors; //array of strings explaining the issue

    private $sModuleName = 'action-whatsapp-notifications';

    // returns a the list of target phone numbers as an array, or a detailed error description
    protected function FindRecipients($sRecipAttCode, $aArgs)
    {
        $sOQL = $this->Get($sRecipAttCode);
        if (strlen($sOQL) == '') return array();
        try
        {
            $oSearch = DBObjectSearch::FromOQL($sOQL);
            $oSearch->AllowAllData();
        }
        catch (OQLException $e)
        {
            $this->m_aWhatsAppErrors[] = "query syntax error for recipient '$sRecipAttCode'";
            return $e->getMessage();
        }

        $sClass = $oSearch->GetClass();
        $sWhatsAppAttCode = Metamodel::GetModuleSetting($this->sModuleName, 'target_attcode', 'whatsapp');

        if (!MetaModel::IsValidAttCode($sClass, $sWhatsAppAttCode, true))
        {
            $this->m_aWhatsAppErrors[] = "wrong target for recipient '$sRecipAttCode'";
            return "The objects of the class '$sClass' do not have any email attribute";
        }

        $oSet = new DBObjectSet($oSearch, array() /* order */, $aArgs);
        $aRecipients = array();
        while ($oObj = $oSet->Fetch())
        {
            $sTarget = trim($oObj->Get($sWhatsAppAttCode));
            if (strlen($sTarget) > 0)
            {
                $aRecipients[] = $sTarget;
                $this->m_iRecipients++;
            }
        }
        return $aRecipients;
    }
    
    public function DoExecute($oTrigger, $aContextArgs)
    {
        if (MetaModel::GetModuleSetting($this->sModuleName, 'enabled', false) !== true) return;
        if (MetaModel::IsLogEnabledNotification())
        {
            // TODO: Create own log class
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
            $this->m_aWhatsAppErrors = array();
            $bRes = false; // until we do succeed in sending the email

            // Determine recicipients
            $aTargets = $this->FindRecipients('target', $aContextArgs);
            $sMessage = MetaModel::ApplyParams($this->Get('message'), $aContextArgs);
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
            if (isset($aTargets)) $oLog->Set('to', implode(', ', $aTargets));
            if (isset($sMessage)) $oLog->Set('body', $sMessage);
        }
        $sUsername = MetaModel::GetModuleSetting($this->sModuleName, 'username', '');
        $sPassword = MetaModel::GetModuleSetting($this->sModuleName, 'password', '');
        if ($sUsername == '' || $sPassword == '') return 'WhatsApp username or password is empty in iTop config file.';
        $sNickname = MetaModel::GetModuleSetting($this->sModuleName, 'nickname', '');
        $bDebug = MetaModel::GetModuleSetting($this->sModuleName, 'debug', false);
        $bLog = MetaModel::GetModuleSetting($this->sModuleName, 'log', false);
        $sDataDir = MetaModel::GetModuleSetting($this->sModuleName, 'data_dir', APPROOT.'/data/wadata');

        if ($this->IsBeingTested())
        {
            $sMessage = 'TEST['.$sMessage.']';
            $aTargets = [$this->Get('test_recipient')];
        }
        
        if (empty($this->m_aWhatsAppErrors))
        {
            if ($this->m_iRecipients == 0)
            {
                return 'No recipient';
            }
            else
            {
                SetupUtils::builddir($sDataDir);
                $oWA = new WhatsProt($sUsername, $sNickname, $bDebug, $bLog, $sDataDir);
                $oWA->connect();
                $oWA->loginWithPassword($sPassword);
                $oWA->sendGetServerProperties();
                $oWA->sendClientConfig();
                $sMessageId = $oWA->sendBroadcastMessage($aTargets, $sMessage);
                $bRes = $oWA->pollMessage();
                if ($bRes)
                {
                    return 'Message sent, id: '.$sMessageId;
                }
                else
                {
                    return 'Error occurred while we were trying to send messages.';
                }
            }
        }
        else
        {
            if (is_array($this->m_aWhatsAppErrors) && count($this->m_aWhatsAppErrors) > 0)
            {
                $sError = implode(', ', $this->m_aWhatsAppErrors);
            }
            else
            {
                $sError = 'Unknown reason';
            }
            return 'Notification was not sent: '.$sError;
        }
    }
}
