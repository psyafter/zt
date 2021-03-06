function showLink(buildID, type, param)
{
    var method = type == 'story' ? 'linkStory' : 'linkBug';
    $.get(createLink('build', method, 'buildID=' + buildID + (typeof(param) == 'undefined' ? '' : param)), function(data)
    {
        var $pane = $(type == 'story' ? '#stories' : '#bugs');
        $pane.find('.main-table').hide();
        var $linkBox = $pane.find('.linkBox').html(data).removeClass('hidden');
        $linkBox.find('[data-ride="table"]').table();
        $.toggleQueryBox(true, $linkBox.find('#queryBox'));
    });

    $('.actions').find("a[href*='" + type + "']").addClass('hidden');
}

$(function()
{
    if(flow != 'onlyTest')
    {
        if(link == 'true') showLink(buildID, type, param);
        var infoShowed = false;
        $('.nav.nav-tabs a[data-toggle="tab"]').on('shown.zui.tab', function(e)
        {
            var href = $(e.target).attr('href');
            var tabPane = $(href + '.tab-pane');
            if(tabPane.size() == 0) return;
            var formID = tabPane.find('.linkBox').find('form:last');
            if(formID.size() == 0) formID = tabPane.find('form:last');

            if(href == '#buildInfo' && !infoShowed)
            {
                var $tr = $('#buildInfo .detail-content .table-data tbody tr:first');
                width   = $tr.width() - $tr.find('th').width();
                $('#buildInfo img').each(function()
                {
                    if($(this).parent().prop('tagName').toLowerCase() == 'a') $(this).unwrap();
                    setImageSize($(this), width, 0);
                });

                infoShowed = true;
            }
        });
    }
})
