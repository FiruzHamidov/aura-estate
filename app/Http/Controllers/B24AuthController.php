<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request; use Firebase\JWT\JWT;

class B24AuthController extends Controller {
    public function issue(Request $r){
        $domain = $r->string('domain'); // проверьте whitelist по env('B24_ALLOWED_DOMAINS')
        $payload = ['sub'=>'b24-widget','dom'=>$domain,'dealId'=>$r->input('dealId'),'iat'=>time(),'exp'=>time()+600];
        $jwt = JWT::encode($payload, env('B24_WIDGET_JWT_KEY'), 'HS256');
        return response()->json(['token'=>$jwt,'exp'=>$payload['exp']]);
    }
}
