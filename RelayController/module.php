<?

require_once(__DIR__ . "/../Logging.php");

class EthernetRelay extends IPSModule
{
    
    public function Create()
    {
        parent::Create();
        $this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");
        
        $this->RegisterPropertyBoolean ("log", false );
		$this->RegisterPropertyString ("password", "" );
   }

    public function ApplyChanges(){
        parent::ApplyChanges();
        
        $this->RegisterVariableInteger("lastreceived", "Last Received");
		$this->RegisterVariableFloat("lastreceivedtime", "Last Received Time");
        
		$this->RegisterVariableBoolean("relay1", "Relay #1", "~Lock");
		$this->RegisterVariableBoolean("relay2", "Relay #2", "~Lock");
		
        IPS_SetHidden($this->GetIDForIdent('lastreceivedtime'), true);
		IPS_SetHidden($this->GetIDForIdent('lastreceived'), true);
				
		$ident="updaterelaystatus";
		$name="Update Relay Status";
		$id = $this->RegisterScript($ident, $name, "<?\n//Do not modify!\nETHR_UpdateRelayStatus(".$this->InstanceID.");\n?>");	
		IPS_SetScriptTimer($id, 60);
   
    }

    public function ReceiveData($JSONString) {
		
		if ($this->Lock("InsideReceive")) { 
			$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));

			try {
				$incomingData = json_decode($JSONString);
				
				$incoming = ord($incomingData->Buffer);
				
				$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
				
				$id = $this->GetIDForIdent("lastreceived");
				SetValueInteger($id, $incoming);
				$id = $this->GetIDForIdent("lastreceivedtime");
				SetValueFloat($id, microtime(true));
				
				return true;
			
			} catch (Exeption $ex){
				$log->LogMessageError("Failed receiving data. Error: ".$ex->getMessage());
				return false;
			
			} finally {
				$this->Unlock("InsideReceive"); 
					
			}
		} else 
			$log->LogMessageError("ReceiveData - Already locked for receiving");
    }
	
	public function UpdateRelayStatus() {
		
		$password = $this->ReadPropertyString("password");
		$authenticted = true;
		
		if(strlen($password)>0) {
			$authenticted = $this->SendCmd(chr(121).$password, "authenticate");
		}
		
		if($authenticted)
			$result = $this->SendCmd(chr(91), "status");
		else
			$result = false;
		
		return $result;
	}
	
	public function SwitchMode(int $RelayNumber, bool $Status) {
		if($Status)
			$cmd = chr(32);
		else 
			$cmd = chr(33);

		
		$password = $this->ReadPropertyString("password");
		$authenticted = true;
		
		if(strlen($password)>0) {
			$authenticted = $this->SendCmd(chr(121).$password, "authenticate");
		}
		
		if($authenticted) {
			$result = $this->SendCmd($cmd.chr($RelayNumber).chr(0), "switch");
			$this->SendCmd(chr(91), "status");
		}
			
		return $result;
	}
	
	
	/*public function SendCommand(string $Command) {
		$this->SendCmd($Command, "switch");
		
	}
	*/
	private function SendCmd($Command, $CommandType) {
		if ($this->Lock("InsideSendCommand")) { 
		
			if(!$this->EvaluateParent())
				return false;
			
			$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
								
			$buffer = $Command;
			
			$log->LogMessage("Sending command: ".$CommandType);
								
			try{
				$time = microtime(true);
				
				$this->SendDataToParent(json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", "Buffer" => $buffer)));
	
				//$log->LogMessage("Time is ".$time);
				$log->LogMessage("Waiting for data received...");
				
				$dataReceived = false;
				$id = $this->GetIDForIdent("lastreceivedtime");
				for($count=0;$count<10;$count++) {
					$receivedTime = GetValueFloat($id);
					//$log->LogMessage("LastReceived update time is ".$receivedTime);
					if($receivedTime>$time) {
						$dataReceived = true;
						break;
					}
					IPS_Sleep(100);
				}

				if($dataReceived) {
					$id = $this->GetIDForIdent("lastreceived");
					$receivedData = GetValueInteger($id);
					
					$log->LogMessage("Data was recieived: ".$receivedData);
														
					if($CommandType=="status") {
						$log->LogMessage("Oppdating relay status");
						$idRelay1 = $this->GetIDForIdent("relay1");
						$idRelay2 = $this->GetIDForIdent("relay2");
						SetValueBoolean($idRelay1, $receivedData & 0x01);
						SetValueBoolean($idRelay2, $receivedData & 0x02);
						
						return true;
					}
					
					if($CommandType=='authenticate') {
						if($receivedData==1) {
							$log->LogMessage("Authentication is OK");
							return true;
						} else {
							$log->LogMessageError("Authenticstion failed!");
							return false;
						}
					}
					
					if($CommandType=='switch') {
						if($receivedData==0) {
							$log->LogMessage("Successfully chenged the state of the relay");
							return true;
						} else {
							$log->LogMessageError("Failed to change the state of the relay!");
							return false;
						}
					}
				} else
					$log->LogMessageError("Did not receive expected data!");
			
				return false;

			} catch (Exeption $ex) {
				$log->LogMessageError("Failed to send the command ".$Command." . Error: ".$ex->getMessage());
				return false;
			
			} finally {
				$this->Unlock("InsideSendCommand"); 
				 
			}
			
		} else {
			$log->LogMessageError("Already locked for sending. Please retry...");
			return false;	
		}
	}
    
	private function Lock($ident){
		$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		for ($i = 0; $i < 200; $i++){
			if (IPS_SemaphoreEnter("ETHR_" . (string) $this->InstanceID . (string) $ident, 1)){
				//$log->LogMessage($ident." is locked"); 
				return true;
			} else {
				if($i==0)
					//$log->LogMessage("Waiting for lock...");
				IPS_Sleep(mt_rand(1, 5));
			}
		}
        
        $log->LogMessageError($ident." is already locked"); 
        return false;
    }

    private function Unlock($ident)
    {
        IPS_SemaphoreLeave("ETHR_" . (string) $this->InstanceID . (string) $ident);
		//$log = new Logging($this->ReadPropertyBoolean("log"), IPS_Getname($this->InstanceID));
		//$log->LogMessage($ident." is unlocked");
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
