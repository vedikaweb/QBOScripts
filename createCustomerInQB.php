<?php

        
        set_time_limit(0);
        include("../db_connect.php");


        // this code update quickbooks access tokens 
        callQbFile();
        function callQbFile(){
            $url='https://localhost/kk/OAuth_2/RefreshToken2.php';
            return curlCall($url);
        }
      
        //quickbooks oauth token information
        $qb_auth_token='';
        $company_id='';
        $base_path='';
        $minorversion='';
        $getToken="SELECT * FROM kk_qb_access"; //get oauth token from database
        $resultgetToken = $conn->query($getToken);
        $tokenInfo=mysqli_fetch_assoc($resultgetToken);
        $qb_auth_token="Bearer ".$tokenInfo['access_token'];
        $company_id=$tokenInfo['realm_id'];
        $base_path=$tokenInfo['base_path'];
        $minorversion=$tokenInfo['minorversion'];
        

        $getContactIdSql="SELECT * FROM `kk_customer` WHERE `qb_status`='0' OR `qb_status`='1'";
    	//file_put_contents("customer.txt","\n".print_r($getContactIdSql ,true),FILE_APPEND);
        $getContactIdSqlRes=$conn->query($getContactIdSql);
        if($getContactIdSqlRes->num_rows>0) {
          while ($contactData=mysqli_fetch_assoc($getContactIdSqlRes)) {
              createContactIfNotFoundId($qb_auth_token,$company_id,$contactData,$conn,$base_path,$minorversion);
          }
        }

    //if customer id found in customer table then create new client in inventory any return customer id
    function createContactIfNotFoundId($qb_auth_token,$company_id,$contactData,$conn,$base_path,$minorversion){

        $FirstName='';
        $LastName='';
        
        $FirstName=$contactData['first_name'];
        $LastName=$contactData['last_name'];
        
        if($LastName==''){
            $LastName=$contactData['first_name'];
            $FirstName='';
        }
        $getNameValue='';
        if($FirstName=='' && $LastName==''){
            $getNameValue=explode("@",$contactData['email']);
            $LastName=$getNameValue[0];
        }
        if($FirstName=='' && $LastName=='' && $contactData['email'] ==''){
            $LastName=$contactData['phone'];
        }

        $Mailing_City=$contactData['bill_city'];
        $Mailing_Street=$contactData['bill_address'];
        $Mailing_State=$contactData['bill_state'];              
        $Mailing_Country=$contactData['bill_country'];
        $Mailing_Zip=$contactData['bill_zip'];
        
        $emailErr = "";
        if(!filter_var($contactData['email'], FILTER_VALIDATE_EMAIL)) {
            $emailErr = "";
        }else{
            $emailErr =$contactData['email'];
        }
        
      // here we create product in quickbooks online
			$customer_qb_Body='';
			$customer_qb_Body.= '{';
			if($contactData['qb_customer_id'] !=''){
			 $customer_qb_Body.='"domain": "QBO", 
                                  "Id": "'.$contactData['qb_customer_id'].'",
                                  "SyncToken": "'.$contactData['sync_token'].'",';
			}
			
		    $customer_qb_Body.= '"FullyQualifiedName": "'.$FirstName.' '.$LastName.'",
					"PrimaryEmailAddr": {
						"Address": "'.$emailErr.'"
					}, 
					"DisplayName": "'.$FirstName.' '.$LastName.'",
					"Suffix": "", 
					"Title": "", 
					"MiddleName": "", 
					"Notes": "", 
					"FamilyName": "", 
					"PrimaryPhone": {
						"FreeFormNumber": "'.$contactData['phone'].'"
					}, 
					"CompanyName": "'.$FirstName.' '.$LastName.'", 
					"BillAddr": {
					  "CountrySubDivisionCode": "", 
					  "City": "'.$Mailing_City.'", 
					  "PostalCode": "'.$Mailing_Zip.'", 
					  "Line1":  "'.$Mailing_Street.'",  
					  "Country": "'.$Mailing_Country.'"
					}, 
					"GivenName": ""
			}';
			
			//echo "<pre>";
			//print_r($customer_qb_Body);
			
			//Quickbooks online api url
			$requestType='POST';
			echo $contactUrlQB =$base_path."/v3/company/".$company_id."/customer?minorversion=".$minorversion;
			 
			//call QB customer api
			$customerResponce_QB=json_decode(curl_call_qb($qb_auth_token,$contactUrlQB,$customer_qb_Body,$requestType),true);
		
			echo "<pre>";
			print_r($customerResponce_QB);
			
			if(isset($customerResponce_QB['Customer']['Id'])){
				$updateContactIdsql="UPDATE `kk_customer` SET `qb_customer_id`='".$customerResponce_QB['Customer']['Id']."',`qb_status`='2',`sync_token`='".$customerResponce_QB['Customer']['SyncToken']."' WHERE `ID`='".$contactData['ID']."'";
				$updateContactRes=$conn->query($updateContactIdsql);
			} 
			// store qb online response id into db 
			if(isset($customerResponce_QB['Fault']['Error'][0])){
			    
			    $message='';
			    $message=$customerResponce_QB['Fault']['Error'][0]['Message'].' '.$customerResponce_QB['Fault']['Error'][0]['Detail'];
			    $status='';
			    //here we manage first attemt ,second attempt status
			    if($contactData['qb_status']==0){
			        $status='1';
			    }elseif($contactData['qb_status']=='1'){
			        $status='3';
			    }else{
			        $status='3';
			    }
			    //update qb status attempt error
			    $updateQbErrContactSql="UPDATE `kk_customer` SET `qb_status`='".$status."' WHERE `ID`='".$contactData['ID']."'";
				$updateQbErrContactSqlRes=$conn->query($updateQbErrContactSql);
			    //insert error details into erroe log table
				$updateQbErrorLogSql="INSERT INTO `kk_error_log`(`module_id`, `module_name`, `message`, `created_at`) VALUES ('".$contactData['ID']."','Qb_Contact','".$message."','".date('Y-m-d h:i:sa')."')";
				$updateQbErrorLogSqlRes=$conn->query($updateQbErrorLogSql);
			} 
			//authentication erroe token expire
			if(isset($customerResponce_QB['fault']['error'][0])){
			    
			    $message='';
			    $message=$customerResponce_QB['fault']['error'][0]['message'].' '.$customerResponce_QB['fault']['error'][0]['detail'];
			    $status='';
			    //here we manage first attemt ,second attempt status
			    if($contactData['qb_status']==0){
			        $status='1';
			    }elseif($contactData['qb_status']=='1'){
			        $status='3';
			    }else{
			        $status='3';
			    }
			    //update qb status attempt error
			    $updateQbErrContactSql="UPDATE `kk_customer` SET `qb_status`='".$status."' WHERE `ID`='".$contactData['ID']."'";
				$updateQbErrContactSqlRes=$conn->query($updateQbErrContactSql);
			    //insert error details into erroe log table
				$updateQbErrorLogSql="INSERT INTO `kk_error_log`(`module_id`, `module_name`, `message`, `created_at`) VALUES ('".$contactData['ID']."','Qb_Contact','".$message."','".date('Y-m-d h:i:sa')."')";
				$updateQbErrorLogSqlRes=$conn->query($updateQbErrorLogSql);
			} 
    }

    


    function curl_call($token,$url,$requestBody,$requestType){
            
          $ch = curl_init();
          curl_setopt($ch, CURLOPT_URL,$url);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
          curl_setopt($ch, CURLOPT_TIMEOUT,30);
          curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
          curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);    
          curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            if($requestType=="POST"){
            curl_setopt($ch, CURLOPT_POST, 1);
            }else{
              curl_setopt($ch, CURLOPT_CUSTOMREQUEST,"PUT");
            }
          curl_setopt($ch,CURLOPT_HTTPHEADER, array(
            "Authorization: ".$token,
            "Content-Type: application/x-www-form-urlencoded"
          ));
          $resData = curl_exec($ch);
          return $resData; 
  }
  function curl_call_qb($token,$url,$requestBody,$requestType){
            
          $ch = curl_init();
          curl_setopt($ch, CURLOPT_URL,$url);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
          curl_setopt($ch, CURLOPT_TIMEOUT,30);
          curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
          curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);    
          curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            if($requestType=="POST"){
            curl_setopt($ch, CURLOPT_POST, 1);
            }else{
              curl_setopt($ch, CURLOPT_CUSTOMREQUEST,"PUT");
            }
          curl_setopt($ch,CURLOPT_HTTPHEADER, array(
            "Authorization: ".$token,
            "Accept: application/json",
            "Content-Type: application/json"
          ));
          $resData = curl_exec($ch);
          return $resData; 
  }

    function curlCall($url){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $result=curl_exec($ch);
        return true; 
    }
?>