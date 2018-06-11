<?php
namespace App\Http\Controllers\Admin;

use Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Merchant;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Category;
use App\Models\MerchantContactInformation;
use App\Models\MerchantBusinessProfile;
use App\Models\MerchantBusinessContact;
use App\Models\MerchantShopDetail;
use App\Models\MerchantBankDetail;
use App\Models\MerchantCashbackRate;
use App\Models\MerchantCoverImage;
use App\Models\CustomerCashbackRate;
use App\Models\Address\District;
use App\Models\Address\Province;
use App\Models\Bank;
use App\Models\Address\SubDistrict;
use App\Helpers\UserNotification as UserNotification;
use Illuminate\Support\Facades\Crypt;
use App\Helpers\ResizeImage as ResizeImage;
use Yajra\Datatables\Facades\Datatables;

class MerchantController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct();         
    }
    
    /* 	
     * Function Name 	: index
     * Method           : GET
     * Description      : For Listing of customers
    */
    public function index($category_id,Request $request){
        
        
        $data['category_id'] = $category_id;
        if($category_id != '0'){            
            $data['category_id'] = base64_decode($category_id);
        }
        $data['categories'] = Category::get();        
        $data['totalMerchants'] = Merchant::count();
        $data['activeMerchants'] = Merchant::where('status',2)->count();
        $data['todayMerchants'] = Merchant::wheredate('created_at',date('Y-m-d'))->count();
        $data['thisMonthMerchants'] = Merchant::whereMonth('created_at',date('m'))->count();
        $data['pendingMerchants'] = Merchant::where('status',0)->where('apply_xcash_service',1)->count();
        $data['depositMerchants'] = Merchant::where('status',1)->where('apply_xcash_service',0)->count();    
        $data['suspendedMerchants'] = Merchant::where('status',3)->count();
        return view('backend.admin.merchant.listing')->withData($data);
    }
    
    /* 	
     * Function Name 	: getmerchants
     * Method           : GET
     * Description      : For Listing of merchants
    */
    
    public function getmerchants(Request $request){
        
        $data = $request->all(); 
        $category_id = $data['category_id'];       
        
        $query= Merchant::with('business_profile');
        
        if($category_id != 0){
            $query->whereHas('business_profile' ,function($query) use ($category_id){ $query->where('category_ids',$category_id);                    });
        }
        
        return Datatables::of( $query->get() )
            ->escapeColumns(['id'])
            ->addColumn('selection', function ($user) {
                return '<input type="checkbox" value="'.$user->id.'" class="bulk_action">';
            })
            ->addColumn('#', function ($user) {
                return 1;
            })
            ->addColumn('points', function ($user) {
                return number_format($user->points, 2);
            })
            ->addColumn('merchant_name', function ($user) {
                return $user->first_name.' '.$user->last_name;
            })
            ->addColumn('contact_name', function ($user) {
                if($user->business_profile)
                    return $user->business_profile->company_name;
                return '-';
            })
            ->addColumn('transactions', function ($user) {
                $transactions = Transaction::where('paid_by','!=','4')->where('merchant_id',$user->id)->count();
                if($transactions > 0){                   
                     return '<a href="'.url('admin/transactions').'/0/'.base64_encode($user->id).'" class="" title="View Transactions">'.$transactions.'</a>';
                }
                return $transactions;
            })
            ->addColumn('email', function ($user) {
                return isset($user->email)?$user->email:'-';
            })
            ->addColumn('phone_number', function ($user) {
                return isset($user->phone_number)?$user->phone_number:'-';
            })
            ->addColumn('type', function ($user) {
                if($user->business_profile){
                    if($user->business_profile->organization_type != 0){
                        return $this->organisation_type[$user->business_profile->organization_type];
                    }
                }else{
                    return '-';
                }
            })
            ->addColumn('status', function ($user) {
                
                if($user->status == 0 && $user->apply_xcash_service == 0){
                    return '<span class="m-badge m-badge--danger m-badge--wide">Incomplete</span>';
                }else if($user->status == 0){
                    return '<a href="#" class="statusRow" data-merchant-id="'.$user->id.'" data-toggle="modal" data-target="#m_modal_status" row-status="'.$user->status.'"><span class="m-badge m-badge--brand m-badge--wide">Pending</span></a>';
                }else if($user->status == 1){
                    return '<a href="#" class="statusRow" data-merchant-id="'.$user->id.'" data-toggle="modal" data-target="#m_modal_status" row-status="'.$user->status.'"><span class="m-badge m-badge--success m-badge--wide">Approved</span></a>';
                }else if($user->status == 2){
                    return '<a href="#" class="statusRow" data-merchant-id="'.$user->id.'" data-toggle="modal" data-target="#m_modal_status" row-status="'.$user->status.'"><span class="m-badge m-badge--success m-badge--wide">InService</span></a>';
                }else if($user->status == 3){
                    return '<a href="#" class="statusRow" data-merchant-id="'.$user->id.'" data-toggle="modal" data-target="#m_modal_status" row-status="'.$user->status.'"><span class="m-badge m-badge--danger m-badge--wide">Suspended</span></a>';
                }
            })
            ->addColumn('actions', function ($user) {

                $view = '<a href="'.url('admin/merchant/detail/1').'/'.base64_encode($user->id).'" class="m-portlet__nav-link btn m-btn m-btn--hover-accent m-btn--icon m-btn--icon-only m-btn--pill" title="View"><i class="la la-eye"></i></a>';
                
                $is_write = Auth::guard('admin')->user()->check_permission('merchant','is_write');
                if($is_write)                   
                $delete = '';
                
                $is_delete = Auth::guard('admin')->user()->check_permission('merchant','is_delete');
                if($is_delete)
                    $delete = '<a href="javascript:void(0);" class="delete_merchant m_sweetalert_demo_9 m-portlet__nav-link btn m-btn m-btn--hover-danger m-btn--icon m-btn--icon-only m-btn--pill" title="Delete" data-row="'.$user->id.'"><i class="la la-trash"></i></a>';
                return $view.$delete;
                
            })
            ->make(true);
    }
    
    /* 	
     * Function Name 	: view
     * Method           : POST
     * Description      : view detail of customer
    */
    
    public function detail($step,$id,Request $request){       
       
        $merchant_id = base64_decode($id);
        
        $merchant = Merchant::with('contact_information','business_profile','business_contact','shop_detail','bank_details','cashback_rate','cover_image')->where('id',$merchant_id)->first();    
        
        /* tab-1 data */
        
        $merchant['step'] = $step;        
        $merchant['redemption_rate'] = config('app.deposit.admin_redemption_rate');
        $merchant['collection_rate'] = $merchant['commission_rate'] = $merchant['branches'] = 0;
        $rates = MerchantCashbackRate::with('customer_cashback_rate')->where('merchant_id',$merchant_id)->first();
        if($rates){
            if($rates->customer_cashback_rate){
                $merchant['collection_rate'] = $rates['customer_cashback_rate']['customer']; //customer
                $merchant['commission_rate'] = $rates['customer_cashback_rate']['xcash']; //xcash rate 
            }
        }         
        if(isset($merchant->shop_detail) && !empty($merchant->shop_detail)){
            $merchant['branches'] =count($merchant->shop_detail);
        }  
        
        /* tab-2 data */
        
        $merchant['totalTransactions'] = Transaction::where('paid_by','!=','4')->where('merchant_id',$merchant_id)->count();
        $merchant['todayTransactions'] = Transaction::where('paid_by','!=','4')->where('merchant_id',$merchant_id)->wheredate('created_at',date('Y-m-d'))->count();
        $merchant['thisMonthTransactions'] = Transaction::where('paid_by','!=','4')->where('merchant_id',$merchant_id)->whereMonth('created_at',date('m'))->count();
        
        $merchant['totalGMV'] = Transaction::where('paid_by','!=','4')->where('merchant_id',$merchant_id)->whereIn('type',[1,4,5])->sum('total_amount');          
        $merchant['averageValue'] = 0;
        $totalTran = Transaction::where('paid_by','!=','4')->where('merchant_id',$merchant_id)->whereIn('type',[1,4,5])->count();
        
        if($totalTran > 0){            
            $merchant['averageValue'] = round( ($merchant['totalGMV'] / $totalTran) , 2); 
        }        
         
        //$commission = number_format( ( ( ($transaction->total_amount)*($transaction->xcash_rate) ) / 100), 2);        
        $merchant['commission_paid'] = 0;        
        $merchant['customers'] = 0;
        $merchant['kickback'] = 0;
        $merchant['redeemed'] = 0; 
        $merchant['balance'] = $merchant['float_points'];         
        
        return view('backend.admin.merchant.view3')->withmerchant($merchant);
    }
    
     /* 	
     * Function Name 	: get_transactions
     * Method           : GET
     * Description      : get listing of Transactions
    */
    public function gettransactions(Request $request){
        
        $data = $request->all();
        
        $merchant_id = $data['merchant_id'];
        $transaction = Transaction::where('paid_by','!=','4')->where('merchant_id',$merchant_id)->get();    
        
        return Datatables::of($transaction)
            ->escapeColumns(['transaction_id','amount','redeemed_points','created_at']) 
            ->addColumn('#', function ($user) {
                return 1;
            })
            ->addColumn('total_amount', function ($transaction) {                
                return number_format($transaction->total_amount,2);
            })
            ->addColumn('collected_points', function ($transaction) {                
                return number_format( ( ( ($transaction->total_amount)*($transaction->customer_rate) ) / 100), 2);
            })
            ->addColumn('commission', function ($transaction) {                
                return number_format( ( ( ($transaction->total_amount)*($transaction->xcash_rate) ) / 100), 2);
            })
            ->addColumn('deposit_withdraw', function ($transaction) {                
                if($transaction->type == '2'){
                    return '+'.number_format($transaction->total_amount,2);
                }elseif($transaction->type == '8'){
                    return '-'.number_format($transaction->total_amount,2);
                }else{
                    return '-';
                }
            })
            ->addColumn('date_time', function ($transaction) {                
                return $transaction->created_at;
            })
            ->addColumn('customer', function ($transaction) {
                $customer = Customer::where('id',$transaction->customer_id)->select('first_name','last_name')->first();
                if($customer)
                    return $customer['first_name'].' '.$customer['last_name'];
                return '-';
            })
            ->addColumn("merchant", function ($transaction) {
                $merchant = Merchant::with('business_profile')->where('id',$transaction->merchant_id)->first();
                if($merchant['business_profile'])
                    return $merchant['business_profile']->company_name;
                return '-';
            })
            ->addColumn("type", function ($transaction) {
                return $transaction->type_label;
            })
            ->addColumn('status', function ($transaction) {
                return $transaction->status_label;
            })   
            ->addColumn('actions', function ($transaction) {
                
                $view = '<a href="'.url('admin/transactions/view').'/'.base64_encode($transaction->id).'" class="m-portlet__nav-link btn m-btn m-btn--hover-accent m-btn--icon m-btn--icon-only m-btn--pill" title="View" data-row="'.$transaction->id.'">
                        <i class="la la-eye"></i></a>';
                
                $dispute = '';
                $dispute = Auth::guard('admin')->user()->check_permission('transaction-history','is_write');
                if($dispute)
                    $dispute = '<a  href="javascript:void(0);" class="dispute_transaction m_sweetalert_demo_9 m-portlet__nav-link btn m-btn m-btn--hover-danger m-btn--icon m-btn--icon-only m-btn--pill" data-row="'.$transaction->id.'" title="Dispute"  ><i class="la la-minus-circle"></i></a>';
                $style = ''; $suspicious = 0;
                if($transaction->status == 4){
                    $style = "color:red";
                    $suspicious = 1;
                }
                $flag = '<a href="javascript:void(0);" class="suspicious_transaction m_sweetalert_demo_9 m-portlet__nav-link btn m-btn m-btn--hover-danger m-btn--icon m-btn--icon-only m-btn--pill" title="Flag" data-status="'.$suspicious.'" data-row="'.$transaction->id.'"  ><i class="la la-flag" style="'.$style.'"></i></a>';
                
                return $view.$dispute.$flag;
            })
            ->make(true);
    }
    
    /* 	
     * Function Name 	: Add
     * Method           : POST
     * Description      :  Add merchant info
    */
    public function add(Request $request){ 
        $data = $request->all();
        
        if ($request->isMethod('post')) {
           
            //print_r($data); die;
            $rules = [
                        'first_name' => 'required',
                        'last_name' => 'required',
                        //'email' => 'email|unique:merchants',
                        //'phone_number' => 'numeric|unique:merchants',
                     ];
            $validationMesssages = array(
                        'first_name.required'=> trans('api_messages.failure.first_name_error'),
                        'last_name.required'=> trans('api_messages.failure.last_name_error'),
                        //'email.unique'=> trans('api_messages.failure.email_unique_error'),
                        //'phone_number.unique'=> trans('api_messages.failure.phone_number_unique_error'),
                 );
            
            if( (empty($data['email'])) && (empty($data['phone_number'])) ){
               $rules['email'] = 'required';
               $validationMesssages['email.required'] =  trans('api_messages.failure.either_email_or_phone_error');
            }
            $validator = \Validator::make($request->all(), $rules,$validationMesssages);
            if ($validator->fails()) {
                 return redirect()->route('admin.merchant.add')
                             ->withErrors($validator)
                             ->withInput();
            }
            
            if($validator->passes()){
                
                /* upload image */
                if(isset($data['logo'])){            
                    $imgpath = config('app.images_path.merchant_logo');
                    $getimageName = time().'.'.$request->logo->getClientOriginalExtension();   
                    if($request->logo->move(public_path($imgpath), $getimageName)){  
                        ResizeImage::resize_image( $getimageName , $imgpath);
                        $data['logo'] = $getimageName;
                    }
                }
                $data['sign_up_via'] = 'phone_number';
                if(isset($data['email'])){
                    $data['sign_up_via'] = 'email';
                }
                $data['status'] = '1';
                $data['points'] = isset($data['points'])?$data['points']:'0.00';
                Merchant::create($data);
                return redirect('admin/merchant/0'); 
            }
        }
        return view('backend.admin.merchant.add');
    }
    
    /* 	
     * Function Name 	: Delete merchant info
     * Method           : GET
     * Description      : delete merchant
    */
    public function delete(Request $request){  
        
        $data = $request->all(); 
        if ($request->isMethod('post')) {           
            Merchant::whereIn('id',$data['id'])->delete();
            return 'true'; 
        }
    }
    
    /* 	
     * Function Name 	: update Status
     * Method           : POST
    */
    public function changeStatus(Request $request){
        $data = $request->all();        
        if ($request->isMethod('post')) {           
            Merchant::where('id',$data['id'])->update(['status'=>$data['status']]);       
            return "true"; 
        }
        return "false";
    }
    
    /* 	
     * Function Name 	: bulk_action
     * Method           : ANY
     * Description      : perform bulk action on Customer
    */
    public function bulkaction($action, $ids,Request $request)
    {
        //if(in_array($action,['activate','deactivate','delete'])){
        if(in_array($action,['activate','deactivate'])){
            if(!empty($ids)){
                $ids = explode(',',$ids);
                $msg = trans('flash.danger.make_selection');
                if($action == 'activate'){
                    Customer::whereIn('id',$ids)->update(['status'=>1]);
                    $msg = trans('flash.danger.merchant_activated');
                }elseif($action == 'deactivate'){
                    Customer::whereIn('id',$ids)->update(['status'=>0]);
                    $msg = trans('flash.danger.merchant_deactivate');
                }
                return redirect('admin/merchant/0')->withFlashSuccess($msg);    
            }else{
                return redirect('admin/merchant/0')->withFlashDanger(trans('flash.danger.make_selection'));    
            }
        }else{
            return redirect('admin/merchant/0')->withFlashDanger(trans('flash.danger.invalid_action'));    
        }
    }
    
    public function get_merchant($merchant_id){
        return  Merchant::with('contact_information','business_profile','business_contact','shop_detail','bank_details','cashback_rate','cover_image')->where('id',$merchant_id )->first();
    }
    
    /* 	
     * Function Name 	: basic_info
     * Method           : POST
     * Description      : Update basic info 
    */
    public function basic_info($merchant_id,Request $request){ 
        $merchant_id = base64_decode($merchant_id);
        $merchant = Merchant::where('id',$merchant_id)->first();
        
        if ($request->isMethod('post')) {
           $data = $request->all();
            unset($data['_token']);
            
            /* upload image */
            if(isset($data['logo'])){            
                $imgpath = config('app.images_path.merchant_logo');
                $getimageName = time().'.'.$request->logo->getClientOriginalExtension();   
                if($request->logo->move(public_path($imgpath), $getimageName)){  
                    ResizeImage::resize_image( $getimageName , $imgpath);
                    $data['logo'] = $getimageName;
                }
            }
            $updateMerchant = Merchant::where('id',$data['id'])->update($data);            
            return redirect('admin/merchant/detail/1/'.base64_encode($merchant_id));
        }
        return view('backend.admin.merchant.edit.basic_info')->withmerchant($merchant);
    }
    
    /* 	
     * Function Name 	: contact_information
     * Method           : POST
     * Description      : Edit Merchant contact information
    */
    public function contact_information($merchant_id,Request $request){ 
        
        $merchant_id = base64_decode($merchant_id);
        $merchant = Merchant::where('id',$merchant_id)->first();
        $merchant['is_admin'] = 1;
        
        if ($request->isMethod('post')) {
            $data = $request->all();
            unset($data['_token']);            
            
            if(empty($data['email']))
                unset($data['email']);
            if(empty($data['secondary_mobile_number']))
                unset($data['secondary_mobile_number']);

            $rules = [
                    'name' => 'required',
                    'email' => 'email',
                    'mobile_number' => 'required|min:10|numeric',
                    'secondary_mobile_number' => 'min:10|numeric',
                ];

            $validationMesssages = array(
                'name.required'=> trans('api_messages.failure.name_error'),
                'email.email'=> trans('api_messages.failure.valid_email_error'),
                'mobile_number.required'=> trans('api_messages.failure.phone_number_error'),
            );
         
            $validator = \Validator::make($data, $rules,$validationMesssages)->validate();
        
            $data = $request->all();
            $contact_info = MerchantContactInformation::updateOrCreate(
                ['merchant_id' => $data['id'] ],
                [
                    'name' => (isset($data['name']))?$data['name']:null,
                    'email' => (isset($data['email']))?$data['email']:null,
                    'mobile_number' => (isset($data['mobile_number']))?$data['mobile_number']:null,
                    'secondary_mobile_number' => (isset($data['secondary_mobile_number']))?$data['secondary_mobile_number']:null                 ]
            );            
            return redirect('admin/merchant/detail/2/'.base64_encode($merchant_id))->withFlashSuccess('Contact Information saved successfully !!');
        }
        return view('backend.admin.merchant.edit.contact_information')->withmerchant($merchant);
    }      
    
}
