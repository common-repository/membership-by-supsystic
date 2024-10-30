(function($, Membership) {
	$(function() {
		var gridTbl = Membership.initJqTbl({
			tblId: 'mbsGroupsTbl'
			,	cols: [
					{name: 'id', label: Membership.trans('ID'), width: '50'}
				,	{name: 'name', label: Membership.trans('Name')}
				,	{name: 'is_blocked', label: Membership.trans('Status')}
				,	{name: 'created_at', label: Membership.trans('Created')}
			]
			,	label: Membership.trans('Groups')
			,	labelPlural: Membership.trans('Groups')
			,	url: 'groups.getTblList'
			,	removeUrl: 'groups.removeById'
		});
	});
}(jQuery, Membership));

(function ($) {
	jQuery('#mbsGroupsTblNavBtnsShell .bulk-actions').on('change', function () {
		var select = jQuery(this);
		var val = select.val();
		var wrapper = select.closest('#mbsGroupsTblNavBtnsShell');
		if(val !== ''){
			wrapper.find('.'+val).show();
			wrapper.find('.bulk-actions-apply-button').show();
		}
		else{
			wrapper.find('.bulk-actions-list').hide();
			wrapper.find('.bulk-actions-apply-button').hide();
		}
	});

	//For now methods work only with status change select
	jQuery('.bulk-actions-apply-button button').on('click', function () {
		var wrapper = jQuery(this).closest('#mbsGroupsTblNavBtnsShell');
		var select = wrapper.find('.bulk-actions');
		var val = select.val();

		if(val !== ''){
			var selectedRowIds = jQuery('#mbsGroupsTbl').jqGrid ('getGridParam', 'selarrrow')
				,	listIds = [];
			for(var i in selectedRowIds) {
				var rowData = jQuery('#mbsGroupsTbl').jqGrid('getRowData', selectedRowIds[ i ]);
				listIds.push( rowData.id );
			}

			var selectedVal = jQuery('.bulk-actions-list select').find(':selected').val();

			Membership.ajax({
				'route': 'groups.changeStatus'
				,	'id': listIds
				,	'status': selectedVal
			}, {'method': 'post'})
				.error(function(response) {
					console.error(response.responseJSON.message);
				})
				.success(function(response){
					if(response.success) {
						jQuery('#mbsGroupsTbl').trigger( 'reloadGrid' );
					}
				});
		}
	})

}(jQuery));