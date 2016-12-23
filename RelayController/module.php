<?

require_once(__DIR__ . "/../Logging.php");

class GlobalCacheIR extends IPSModule
{
    
    public function Create()
    {
        parent::Create();
        $this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");
        
        $this->RegisterPropertyBoolean ("log", false );
   }

    public function ApplyChanges(){
        parent::ApplyChanges();
        
        $this->RegisterVariableString("LastSendt", "LastSendt");
        
        IPS_SetHidden($this->GetIDForIdent('LastSendt'), true);
   
    }

    public function ReceiveData($JSONString) {
		$incomingData = json_decode($JSONString);
		$incomingBuffer = utf8_decode($incomingData->Buffer);
		
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		$log->LogMessage("Received: ".$incomingBuffer);
		
		if ($this->Lock("LastReceivedLock")) { 
             	    $Id = $this->GetIDForIdent("LastReceived");
		    SetValueString($Id, $incomingBuffer);
		    $log->LogMessage("Updated variable LastReceived");
		    $this->Unlock("LastReceivedLock"); 
		    
                } 

		return true;
    }
	
	public function SendCommand($Device, $Command) {
		if(!$this->EvaluateParent())
			return false;
		
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));

		$log->LogMessage("Sending command: ".$Device.":".$Command);
		$buffer = ":". $Command;
		try{
			$this->SendDataToParent(json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", "Buffer" => $buffer)));

			if ($this->Lock("LastSendtLock")) { 
			    $Id = $this->GetIDForIdent("LastSendt");
			    SetValueString($Id, $buffer);
			    $log->LogMessage("Updated variable LastSendt");
			    $this->Unlock("LastSendtLock"); 
			} 

		} catch (Exeption $ex) {
			$log->LogMessageError("Failed to send the command ".$Device.":".$Command." . Error: ".$ex->getMessage());

			return false;
		}

		return true;

	}
    
	private function Lock($ident){
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		for ($i = 0; $i < 100; $i++){
			if (IPS_SemaphoreEnter("ETHR_" . (string) $this->InstanceID . (string) $ident, 1)){
				$log->LogMessage($ident." is locked"); 
				return true;
			} else {
				if($i==0)
					$log->LogMessage("Waiting for lock...");
				IPS_Sleep(mt_rand(1, 5));
			}
		}
        
        $log->LogMessage($ident." is already locked"); 
        return false;
    }

    private function Unlock($ident)
    {
        IPS_SemaphoreLeave("ETHR_" . (string) $this->InstanceID . (string) $ident);
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		$log->LogMessage($ident." is unlocked");
    }
	
	private function HasActiveParent(){
        $instance = IPS_GetInstance($this->InstanceID);
        if ($instance['ConnectionID'] > 0){
            $parent = IPS_GetInstance($instance['ConnectionID']);
            if ($parent['InstanceStatus'] == 102)
                return true;
        }
        return false;
    }
	
	private function EvaluateParent() {
    	$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		
		if($this->HasActiveParent()) {
            $instance = IPS_GetInstance($this->InstanceID);
            $parentGUID = IPS_GetInstance($instance['ConnectionID'])['ModuleInfo']['ModuleID'];
            if ($parentGUID == '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}') {
				$log->LogMessage("The parent I/O port is active and supported");
				return true;
			} else
				$log->LogMessageError("The parent I/O port is not supported");
		} else
			$log->LogMessageError("The parent I/O port is not active.");
		
		return false;
	}
}

?>