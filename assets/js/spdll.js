            jQuery(document).ready(function($){
                $('.spdll-delete-device').on('click', function(e){
                    e.preventDefault();
                    if(confirm('Are you sure you want to delete this device?')){
                        var user_id = $(this).data('user-id');
                        var device_id = $(this).data('device-id');

                        $.post(ajaxurl, {
                            action: 'spdll_delete_device',
                            user_id: user_id,
                            device_id: device_id,
                            _wpnonce: spdll_ajax.nonce
                        }, function(response){
                            if(response.success){
                                location.reload();
                            } else {
                                console.log(response.data);
                                alert('Failed to delete device.');
                            }
                        });
                    }
                });
            });