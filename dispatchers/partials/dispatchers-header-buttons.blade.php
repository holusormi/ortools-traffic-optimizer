@if(is_active( 'admin/dispatchers' )   ||  isset($show_datatable_btn) )
@section('offcanvas')
@parent
<?php
$p_data=[];
!isset($prepend_to_dispatchers_menu) ?  $prepend_to_dispatchers_menu='' : '';
isset($ModuleField) ?  $p_data['ModuleField']=$ModuleField : '';
isset($table_id) ?  $p_data['table_id']=$table_id : '';
isset($puid) ? $p_data['puid']=$puid.'-offcanvas' : null; 
 ?>
@include("backend.includes.partials.datatable-options-offcanvas", $p_data)

 @endsection
@endif

<!--Action Button-->
<div class="btn-group dropups">
    <?php  $link_cls='btn btn-light rounded-0 w-100 flex-column ';   ?>
    {!! trans( 'buttons.general.action_btn_offcanvas' ) !!}   
        @section('offcanvas-right-sm')   
        <div class="offcanvas-right-sm-menu ">    
            <a href="{{ route( 'admin.dispatchers.index' ) }}" class="{{ $link_cls}}">
                <i class="ph-list-bullets ph-2x "></i>
                {{ trans( 'menus.backend.dispatchers.all' ) }}
            </a>
 
               {!! $prepend_to_dispatchers_menu  ?? '' !!}   
    </div>
    @endsection
</div>
<div class="clearfix"></div>
 
