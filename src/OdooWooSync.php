<?php
namespace Mojtaba\ZarsamOdoo;

if (!defined('ABSPATH')) exit;

class OdooWooSync {

    private $db = "product";
    private $uid = 2;
    private $api_key = "d004b8fd1e85e199c568577fbb36db9491933753";

    public function __construct() {
        add_action('admin_menu', [$this,'menu']);
        add_action('user_register', [$this,'create_customer']);
        add_action('woocommerce_new_order', [$this,'create_order']);
    }

    public function menu(){
        add_menu_page(
            'Odoo Sync',
            'Odoo Sync',
            'manage_options',
            'odoo-sync',
            [$this,'settings_page']
        );
    }

    public function settings_page(){

        if(isset($_POST['base_url'])){
            update_option('odoo_base_url', sanitize_text_field($_POST['base_url']));
        }

        $base_url = get_option('odoo_base_url');
        ?>

        <div class="wrap">
            <h2>Odoo Settings</h2>
            <form method="post">
                <input type="text" name="base_url" value="<?php echo esc_attr($base_url); ?>" style="width:400px">
                <button class="button button-primary">Save</button>
            </form>
        </div>

        <?php
    }

    private function call_odoo($method,$data){

        $base = get_option('odoo_base_url');

        $body = [
            "jsonrpc"=>"2.0",
            "method"=>"call",
            "params"=>[
                "service"=>"object",
                "method"=>"execute_kw",
                "args"=>[
                    $this->db,
                    $this->uid,
                    $this->api_key,
                    "res.partner.api",
                    $method,
                    [$data]
                ]
            ],
            "id"=>1
        ];

        wp_remote_post($base.'/jsonrpc',[
            'headers'=>['Content-Type'=>'application/json'],
            'body'=>json_encode($body)
        ]);
    }

    public function create_customer($user_id){

        $user = get_userdata($user_id);

        $data = [
            "customer_id"=>$user_id,
            "customer_name"=>$user->display_name,
            "customer_mobile"=>get_user_meta($user_id,'billing_phone',true),
            "customer_birthdate"=>"",
            "customer_partner_birthdate"=>"",
            "customer_wedding_date"=>""
        ];

        $this->call_odoo("create_customer",$data);
    }

    public function create_order($order_id){

        $order = wc_get_order($order_id);

        $products = [];

        foreach($order->get_items() as $item){

            $products[] = [
                "products id in order"=>$item->get_product_id(),
                "fee"=>$item->get_total(),
                "qty"=>$item->get_quantity()
            ];
        }

        $data = [
            "warehouse_id"=>1,
            "woo_order_id"=>$order_id,
            "create_order_date"=>$order->get_date_created()->date('Y-m-d'),
            "product_ids"=>$products,
            "price"=>$order->get_total(),
            "dobare_discount"=>0,
            "price_with_discount"=>$order->get_total(),
            "customer_id"=>$order->get_customer_id(),
            "customer_name"=>$order->get_billing_first_name().' '.$order->get_billing_last_name(),
            "customer_mobile"=>$order->get_billing_phone(),
            "customer_birthdate"=>"",
            "customer_partner_birthdate"=>"",
            "customer_wedding_date"=>""
        ];

        $this->call_odoo("create_order",$data);
    }

}
