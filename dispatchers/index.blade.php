@extends ('backend.layouts.'.config('settings.layout_view.app_layout','app'))
<?php
$req=request()->all();
$pass_data=isset($pass_data) ? array_merge($pass_data,$req) :$req;
$card_default_bg=config('settings.layout_view.card.default_bg');
$puid=$table_id=isset($table_id) ? $table_id :'dispatchers-partials-optimal-dt-table';

$form_view=isset($data['form_view']) ? $data['form_view'] :'dispatchers.form';
$table_id=isset($table_id) ? $table_id :'dispatchers-partials-optimal-dt-table';
 $show_datatable_btn=1;
$name=  'Диспетчер' ; 
 $topMenu="top-navbar"; $header_title_cls='card-title';
 $display_name='';
 $card_header_cls="card-header  p-1 with-border";
$card_header_tcls="card-tools m-1";
$dt_search='advance_search';

 if(config('settings.layout_view.app_layout')){
    $topMenu="nav-right-title";
    $header_title_cls='p-0 m-0';
    $card_header_cls="card-header d-sm-flex align-items-sm-center p-0 m-0";
    $card_header_tcls="mt-2 mt-sm-0 ms-sm-auto";
    $dt_search='advance_search_alt';
 }
 $search_input=__('datatable.'.$dt_search,['table_id'=>$table_id,'div_id'=>$table_id.'_search','elem'=>$puid.'-offcanvas']);

 ?>

@section ('title', $name)

@section('page-header')
    <h4 class="page-title mb-0 " style="text-align: center;">{{$name }}</h4>
@endsection

@section($topMenu) 
   @include('backend.dispatchers.partials.dispatchers-header-buttons')           
@endsection
@section('content') 
 
<div class="card {{$card_default_bg}} mb-5 " >
    <div class="{{$card_header_cls}}">  
    <div class="{{$card_header_tcls}}">     
     </div><!--card-header-->
</div>   
    <div class="{{ config('layout.card.table.body','card-body p-0 m-0')}}">    

                <?php 
    $GapiKey = config('settings.uapp_api.maps.api_key','YOUR_DEFAULT_KEY');
?>
    {{--  @include('backend.dispatchers.partials.dispatch')   --}}          
   @include('backend.dispatchers.partials.map')         

</div><!-- /.card-body -->
</div><!--card-->

 
 
         
@endsection

@section('after-scripts')
  
 
    <script>
     
   
 
    </script>
@endsection