<?php
/**
 * Invoice Ninja (https://invoiceninja.com)
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2019. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace App\Models;

use App\DataMapper\ClientSettings;
use App\DataMapper\CompanySettings;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyGateway;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Filterable;
use App\Models\GatewayType;
use App\Models\GroupSetting;
use App\Models\Timezone;
use App\Utils\Traits\CompanyGatewaySettings;
use App\Utils\Traits\GeneratesCounter;
use App\Utils\Traits\MakesDates;
use App\Utils\Traits\MakesHash;
use Hashids\Hashids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Laracasts\Presenter\PresentableTrait;

class Client extends BaseModel
{
    use PresentableTrait;
    use MakesHash;
    use MakesDates;
    use SoftDeletes;
    use Filterable;
    use GeneratesCounter;
    
    protected $presenter = 'App\Models\Presenters\ClientPresenter';

    protected $hidden = [
        'id',
        'private_notes',
        'user_id',
        'company_id',
        'backup',
        'settings',
        'last_login',
        'private_notes'
    ];
   
    protected $fillable = [
        'name',
        'website',
        'private_notes',
        'industry_id',
        'size_id',
        'currency_id',
        'address1',
        'address2',
        'city',
        'state',
        'postal_code',
        'country_id',
        'custom_value1',
        'custom_value2',
        'custom_value3',
        'custom_value4,',
        'shipping_address1',
        'shipping_address2',
        'shipping_city',
        'shipping_state',
        'shipping_postal_code',
        'shipping_country_id',
        'settings',
        'payment_terms',
        'vat_number',
        'id_number',
    ];
    
    /*
    protected $with = [
        'contacts', 
        'primary_contact', 
        'country', 
        'shipping_country', 
        'company'
    ];
    */
    protected $casts = [
        'settings' => 'object'
    ];

    public function gateway_tokens()
    {
        return $this->hasMany(ClientGatewayToken::class);
    }

    /**
     * Retrieves the specific payment token per
     * gateway - per payment method
     *
     * Allows the storage of multiple tokens
     * per client per gateway per payment_method
     * 
     * @param  int $gateway_id          The gateway ID
     * @param  int $payment_method_id   The payment method ID
     * @return ClientGatewayToken       The client token record
     */
    public function gateway_token($gateway_id, $payment_method_id)
    {
        return $this->gateway_tokens
                    ->whereCompanyGatewayId($gateway_id)
                    ->wherePaymentMethod_id($payment_method_id)
                    ->first();
    }

    public function contacts()
    {
        return $this->hasMany(ClientContact::class)->orderBy('is_primary', 'desc');
    }

    public function primary_contact()
    {
        return $this->hasMany(ClientContact::class)->whereIsPrimary(true);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function shipping_country()
    {
        return $this->belongsTo(Country::class, 'shipping_country_id', 'id');
    }

    public function timezone()
    {
        return Timezone::find($this->getSetting('timezone_id'));
    }

    public function date_format()
    {
        return $this->getSetting('date_format');
    }

    public function datetime_format()
    {
        return $this->getSetting('datetime_format');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * 
     * Returns the entire filtered set 
     * of settings which have been merged from
     * Client > Group > Company levels
     * 
     * @return object stdClass object of settings
     */
    public function getMergedSettings()
    {

        if($this->group_settings !== null)
        {

            $group_settings = ClientSettings::buildClientSettings($this->group_settings->settings, $this->settings);

            return ClientSettings::buildClientSettings($this->company->settings, $group_settings);

        }

        return ClientSettings::buildClientSettings($this->company->settings, $this->settings);
    }

    /**
     * 
     * Returns a single setting
     * which cascades from
     * Client > Group > Company
     * 
     * @param  string $setting The Setting parameter
     * @return mixed          The setting requested
     */
    public function getSetting($setting)
    {
        //check client level first
        if($this->settings && (property_exists($this->settings, $setting) !== false) && (isset($this->settings->{$setting}) !== false) ){

            /*need to catch empt string here*/
            if(is_string($this->settings->{$setting}) && (iconv_strlen($this->settings->{$setting}) >=1)){
                return $this->settings->{$setting};
            }
        }

        //check group level (if a group is assigned)
        if($this->group_settings && (property_exists($this->group_settings->settings, $setting) !== false) && (isset($this->group_settings->settings->{$setting}) !== false)){

           return $this->group_settings->settings->{$setting};
        }
        
        //check company level
        if((property_exists($this->company->settings, $setting) != false ) && (isset($this->company->settings->{$setting}) !== false) ){

            return $this->company->settings->{$setting};
        }
        
        throw new \Exception("Settings corrupted", 1);
        
    
        
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function group_settings()
    {
        return $this->belongsTo(GroupSetting::class);
    }

    /**
     * Returns the first Credit Card Gateway
     *     
     * @return NULL|CompanyGateway The Priority Credit Card gateway
     */
    public function getCreditCardGateway() :?CompanyGateway
    {
        $company_gateways = $this->getSetting('company_gateways');

        /* If we have a custom gateway list pass this back first */
        if($company_gateways)
            $gateways = $this->company->company_gateways->whereIn('id', $company_gateways);
        else
            $gateways = $this->company->company_gateways;

        foreach($gateways as $gateway)
        {

            if(in_array(GatewayType::CREDIT_CARD, $gateway->driver($this)->gatewayTypes()))
                return $gateway;

        }

        return null;
    }

    /**
     * Generates an array of payment urls per client
     * for a given amount.
     *
     * The route produced will provide the 
     * company_gateway and payment_type ids
     *
     * The invoice/s will need to be injected
     * upstream of this method as they are not 
     * included in this logic.
     * 
     * @param  float $amount The amount to be charged
     * @return array         Array of payment labels and urls
     */
    public function getPaymentMethods($amount) :array
    {

        $company_gateways = $this->getSetting('company_gateways');
        
        if($company_gateways)
            $gateways = $this->company->company_gateways->whereIn('id', $payment_gateways);
        else
            $gateways = $this->company->company_gateways;

        $gateways->filter(function ($method) use ($amount){
            if($method->min_limit !==  null && $amount < $method->min_limit)
                return false;

            if($method->max_limit !== null && $amount > $method->min_limit)
                return false;
        }); 

        $payment_methods = [];

        foreach($gateways as $gateway)
            foreach($gateway->driver($this)->gatewayTypes() as $type)
                $payment_methods[] = [$gateway->id => $type]; 
            

        $payment_methods_collections = collect($payment_methods);

        //** Plucks the remaining keys into its own collection
        $payment_methods_intersect = $payment_methods_collections->intersectByKeys( $payment_methods_collections->flatten(1)->unique() );

        $payment_urls = [];

        foreach($payment_methods_intersect as $key => $child_array)
        {
            foreach($child_array as $gateway_id => $gateway_type_id)
            {

            $gateway = $gateways->where('id', $gateway_id)->first();

            $fee_label = $gateway->calcGatewayFeeLabel($amount, $this);

            $payment_urls[] = [
                'label' => ctrans('texts.' . $gateway->getTypeAlias($gateway_type_id)) . $fee_label,
                'url'   =>  URL::signedRoute('client.payments.process', [
                                            'company_gateway_id' => $gateway_id,
                                            'gateway_type_id' => $gateway_type_id])
                            ];
            }

        }

            return $payment_urls;
    }


}
