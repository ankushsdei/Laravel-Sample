<?php
namespace App\Http\Controllers\Customer\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SyncContact;
use App\Models\Transaction;
use App\Models\CustomerFavourite;
use Validator; 
use App\Helpers\UserNotification as UserNotification;
use Illuminate\Support\Facades\Crypt;


class TransferController extends Controller
{
    /**
     *  headers : {"content-type":"Application/json","accept":"Application/json","device-token":"1235656","device-type":"ios","app-version":"1.0","access-token":"","Accept-Language":"en","merchant-id":""}
     */
    public function __construct(Request $request)
    {
        parent::__construct();
        parent::set_headers($request);
    }
    
    public function getErrors($errors = null) {
        $error_msg = '';
        if (!empty($errors)) {
            foreach ($errors as $key1 => $error) {
                foreach ($error as $key2 => $text) {
                    $error_msg = $text;
                    break;
                }
            }
        }
        return $error_msg;
    }  
    
    /* 	
     * function name 	: listOfAllCustomers
     * input 		    : {"?type=all&per_page=10&page=1"}
     * Method           : GET
     * Description		: List of all customers
    */
    public function listOfAllCustomers(Request $request){  
        
        $status= 'FAIL';  $message = ''; $responseCode = '400'; $customers = []; 
        $data = $request->query();
        $customer_id = $this->customer_id;
        $customerInfo = Customer::where('id',$customer_id)->select('phone_number')->first();        
        
        $type = isset($data['type'])?$data['type']:'all';
        
        $customers['total_records'] = $totalCustomers = Customer::where('status','1')->where('phone_number','!=', $customerInfo['phone_number'])->count();
        
        if($type == 'fav'){
          $customers['total_records'] = $totalCustomers = CustomerFavourite::with(['customer'])->whereHas('customer', function ($q){
                    $q->whereNull('deleted_at');
                })->where('customer_id',$customer_id)->count();  
        }
        
        $perPage = $data['per_page'];        
        $meta = array("page"=> $data['page'] , "perpage" => $perPage , "total" => $totalCustomers);

        $skip = ($data['page'] - 1) * $meta['perpage'];
        
        $customerData = Customer::where('status','1')->where('phone_number','!=', $customerInfo['phone_number'])->select('id','first_name','last_name','email','phone_number','profile_image','dob')->skip($skip)->take($meta['perpage'])->orderBy('created_at', 'desc')->get(); 
        
        if($type == 'fav'){            
            $customerFavData = CustomerFavourite::with(['customer'=>function($query){
                    $query->select('id','first_name','last_name','phone_number','profile_image');
                }])->whereHas('customer', function ($q){
                    $q->whereNull('deleted_at');
                })->where('customer_id',$customer_id)->skip($skip)->take($meta['perpage'])->orderBy('created_at', 'desc')->get();           
            $customerDatas = [];
            foreach($customerFavData as $customerFavDta){
                if($customerFavDta['customer']){
                    $customerDatas[] = $customerFavDta['customer'];
                }
            }            
            $customerData = $customerDatas;
        }
        
        if(!empty($customerData)){
            $status= 'OK'; $responseCode = '200'; 
            $message = trans('api_messages.success.data_found');
        }else{
             $message = trans('api_messages.failure.data_not_found');      
        }            
        
        $customers['customers'] = $customerData;
        
        $response['status'] = $status;
        $response['message']= $message;
        $response['data']   = $customers;        
        return response()->json($response,$responseCode);
    }
    
    /* 	
     * function name 	: syncContacts
     * input 		    : {"records": [ ]}
     * Method           : POST
     * Description		: Sync contacts from Phone
    */
    
    public function syncContacts(Request $request){   
        
        $status= 'FAIL'; $message = ''; $responseCode = '400'; $result = []; 
        $data = $request->all(); 
        
        $customer_id = $this->customer_id;
        $customerInfo = Customer::where('id',$customer_id)->select('phone_number')->first();        
        
        $rules = ['records' => 'required'];              
        $validator = \Validator::make($data, $rules);
        
        if ($validator->fails()){
            $messages = $validator->messages()->toArray();
            $message = $this->getErrors($messages);     
        } 
         
        if($validator->passes()){
            
            $status= 'OK';  $responseCode = '200';
            
            foreach($data['records'] as $customer){
                
                $name = $customer['name'];
                $phone_number = $customer['phone_number'];
                
                if($customerInfo['phone_number'] != $phone_number){
                    
                    if($existCustomer = Customer::where('phone_number',$phone_number)->select('id','first_name','last_name','profile_image','phone_number')->first()){
                        $result['records'][] = [
                                        'id' =>$existCustomer['id'],
                                        'name' => $existCustomer['first_name'].' '.$existCustomer['last_name'],
                                        'phone_number' => $existCustomer['phone_number'],
                                        'profile_image' => $existCustomer['profile_image_org'],
                                        'is_app_installed'=> '1'
                                    ];
                        /* Save into database */
                        $existContacts = SyncContact::where('customer_id',$customer_id)->where('sync_id',$existCustomer['id'])->first();
                        if(empty($existContacts)){
                            $syncData['customer_id'] = $customer_id;
                            $syncData['sync_id'] = $existCustomer['id'];
                            SyncContact::create($syncData);  
                        }
                    }else{
                        $result['records'][] = [
                                        'name' => $customer['name'],
                                        'phone_number' => $customer['phone_number'],
                                        'is_app_installed'=> '0'
                                    ];
                    }  
                    
                }
            }            
        }
        
        $response['status'] = $status;
        $response['message'] = $message; 
        $response['data'] = $result;       	
        return response()->json($response,$responseCode);      
    }
    
    /* 	
     * function name 	: searchCustomers
     * input 		: {"?type=all&q=77779 phone_number or name"}
     * Method           : GET
     * Description		: Search from all customers
    */
    public function searchCustomers(Request $request){  
        
        $status= 'FAIL';  $message = ''; $responseCode = '400'; $customers = []; 
        $customer_id = $this->customer_id;
        $customerInfo = Customer::where('id',$customer_id)->select('phone_number')->first();        
        
        $data = $request->query();
        
        $type = isset($data['type'])?$data['type']:'all';
        
        $customerData = Customer::where('status','1')->where('phone_number','!=', $customerInfo['phone_number'])->where('first_name', 'like', '%' . $data['q'] . '%')->orWhere('last_name', 'like', '%' . $data['q'] . '%')->orWhere('phone_number', 'like', '%' . $data['q'] . '%')->select('id','first_name','last_name','email','phone_number','profile_image','dob')->get();  
        
        if(!empty($customerData)){
            $status= 'OK'; $responseCode = '200'; 
            $message = trans('api_messages.success.data_found');
        }else{
             $message = trans('api_messages.failure.data_not_found');      
        }            
        
        $customers['customers'] = $customerData;
        
        $response['status'] = $status;
        $response['message']= $message;
        $response['data']   = $customers;        
        return response()->json($response,$responseCode);
    }
    
    /* 	
     * function name 	: makeFavContacts
     * input 		: {"fav_customer_id":"1","status":"1"}
     * Method           : POST
     * Description		: Favourite contacts
    */
    
    public function makeFavContacts(Request $request){   
        
        $status= 'FAIL'; $message = ''; $responseCode = '400'; 
        $data = $request->all();
       
        $data['customer_id'] = $this->customer_id;        
        
        $rules = ['fav_customer_id' => 'required'];              
        $validator = \Validator::make($data, $rules);
        
        if ($validator->fails()){
            $messages = $validator->messages()->toArray();
            $message = $this->getErrors($messages);     
        } 
         
        if($validator->passes()){  
            
            $status= 'OK';  $responseCode = '200';
            
            if(isset($data['status']) && $data['status'] == '0'){
                //delete entry
                CustomerFavourite::where('customer_id',$data['customer_id'])->where('fav_customer_id',$data['fav_customer_id'])->delete();
                $message = trans('api_messages.success.sucessfully_unfavourite');
            }
            
            if(isset($data['status']) && $data['status'] == '1'){
                if(CustomerFavourite::updateOrCreate(['fav_customer_id' => $data['fav_customer_id'],'customer_id' => $data['customer_id']], $data)){                   
                    $message = trans('api_messages.success.sucessfully_favourite');            
                }else{
                    $message = trans('api_messages.failure.network_error');
                } 
            }
        }
        
        $response['status'] = $status;
        $response['message'] = $message; 
        $response['data'] = $data;       	
        return response()->json($response,$responseCode);      
    }
    
    /* 	
     * function name 	: getSyncCustomerProfile
     * input 		: {"sync_id":"2"}
     * Method           : POST
     * Description		: get synced customer info
    */
    public function getSyncCustomerProfile(Request $request){
        
        $status= 'FAIL'; $customer = []; $message = ''; $responseCode = '400'; 
        $data = $request->all();     
        $customer_id = $this->customer_id;
        
        $rules = ['sync_id' => 'required'];              
        $validator = \Validator::make($data, $rules);
        
        if ($validator->fails()){
            $messages = $validator->messages()->toArray();
            $message = $this->getErrors($messages);     
        } 
         
        if($validator->passes()){ 
            
            $customer = Customer::where('id',$data['sync_id'])->where('status','1')->select('id','first_name','last_name','email','phone_number','profile_image','dob','citizen_id')->first();
            
            if($customer){ 
                
                $customer['is_favourite'] = 0;
                $customer['citizen_id'] = $customer['extended_citizen_id'];
                $favCustomer = CustomerFavourite::where('customer_id',$customer_id)->where('fav_customer_id',$data['sync_id'])->first();
                if($favCustomer){
                    $customer['is_favourite'] = 1;
                }
                unset($customer['extended_citizen_id']);
                $status = 'OK';
                $message = trans('api_messages.success.user_found');
                $responseCode = '200';
            }else{
                $message = trans('api_messages.failure.user_not_found');
            }
        }
        $response['status'] = $status;
        $response['message'] = $message;
        $response['data'] = $customer;       	
        return response()->json($response,$responseCode);        
    }
    
    /* 	
     * function name 	: scanToTransfer
     * input 		: {"customer_qr_code":"121221:2","points":"50"}
     * Method           : POST
    */
    public function scanToTransfer(Request $request){
        
        $status= 'FAIL'; $transaction = $customer = []; $message = ''; $responseCode = '400'; 
        $data = $request->all();     
        $customer_id = $this->customer_id;
        
        $rules = ['customer_qr_code' => 'required'];              
        $validator = \Validator::make($data, $rules);
        
        if ($validator->fails()){
            $messages = $validator->messages()->toArray();
            $message = $this->getErrors($messages);     
        } 
         
        if($validator->passes()){ 
            
            $type = isset($data['type'])?$data['type']:'';
            $customer_scan_id = $data['customer_qr_code'];
                    
            if (strpos($data['customer_qr_code'], ':') !== false) {
                $customerInfo = explode(':',$data['customer_qr_code']);
                $customer_scan_id = $customerInfo['1'];
            }else if(strpos($data['customer_qr_code'], '1510') !== false){
                $customerInfo = explode('1510',$data['customer_qr_code']);
                $customer_scan_id = $customerInfo['1'];
            }            
            
            $ownerInfo = Customer::where('id',$customer_id)->where('status','1')->select('points')->first();
            $ownerPoints = $ownerInfo['points'];
            $transferredPoints = $data['points'];
            
            if($transferredPoints <= $ownerPoints){ 
                
                /*Update owner account */
                $ownerData['points'] = $ownerPoints - $transferredPoints;
                $updateOwner = Customer::where('id',$customer_id)->update($ownerData);
                
                /*Update customer account */
                $customerInfo = Customer::where('id',$customer_scan_id)->where('status','1')->select('points')->first();
                $customerData['points'] = $customerInfo['points'] + $transferredPoints;                
                $updateCustomer = Customer::where('id',$customer_scan_id)->update($customerData);   
                
                $transData['transaction_id'] = round(microtime(true) * 1000);
                $transData['customer_id'] = $customer_id;
                $transData['amount'] = UserNotification::convert_to_decimal( '0.00' ); 
                $transData['redeemed_points'] = UserNotification::convert_to_decimal( $transferredPoints );
                $transData['total_amount'] =  UserNotification::convert_to_decimal( $transferredPoints );
                $transData['paid_by'] = 3;
                $transData['points_transfer_to'] = 1;
                $transData['points_transfer_id'] = $customer_scan_id;
                $transData['type'] = 3;
                $transData['payment_via'] = '0';  
                $transData['remarks'] = ''; 
                $transData['status'] = 1;

                $transaction = Transaction::create($transData);  
                
                $status = 'OK';
                $message = trans('api_messages.success.sucessfully_sent');
                $responseCode = '200';
            }else{
                $message = trans('api_messages.failure.insufficient_transfer_balance');
            }
            
        }
        $response['status'] = $status;
        $response['message'] = $message;
        $response['data'] = $transaction;       	
        return response()->json($response,$responseCode);        
    }
    
     /* 	
     * function name 	: scanCustomerInfo
     * input 			: {"scan_customer_id":"2"}
     * Method           : POST
     * Description		: get synced customer info
    */
    public function scanCustomerInfo(Request $request){
        
        $status= 'FAIL'; $customer = []; $message = ''; $responseCode = '400'; 
        $data = $request->all();     
        $customer_id = $this->customer_id;
        
        $rules = ['scan_customer_id' => 'required'];              
        $validator = \Validator::make($data, $rules);
        
        if ($validator->fails()){
            $messages = $validator->messages()->toArray();
            $message = $this->getErrors($messages);     
        }
         
        if($validator->passes()){           
            
            $customer_scan_id = $data['scan_customer_id'];
            
            if (strpos($data['scan_customer_id'], ':') !== false) {
                $customerInfo = explode(':',$data['scan_customer_id']);
                $customer_scan_id = $customerInfo['1'];
            }else if(strpos($data['scan_customer_id'], '1510') !== false){
                $customerInfo = explode('1510',$data['scan_customer_id']);
                $customer_scan_id = $customerInfo['1'];
            }
            
            $customerInfo = Customer::where('id',$customer_scan_id)->where('status','1')->first();
            
            if($customerInfo){                
                $customer = $customerInfo; 
                $status = 'OK';
                $message = trans('api_messages.success.user_found');
                $responseCode = '200';
            }else{
                $message = trans('api_messages.failure.user_not_found');
            }
        }
        $response['status'] = $status;
        $response['message'] = $message;
        $response['data'] = $customer;       	
        return response()->json($response,$responseCode);        
    }


}
