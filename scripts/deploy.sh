server=bitnami@18.132.175.195
content_dest=/home/bitnami/apps/wordpress/htdocs/wp-content

function deploy() {
	scp -pr "$1" "$server:$2"
}
function deploy_all() {
	for f in $(ls $1)
	do
		scp -pr "$1/$f" "$server:$2"
	done
}
function clean() {
	ssh "$server" "rm -rf $1"
	ssh "$server" "mkdir -p $1"
}

theme_dest=$content_dest/themes/greens
plugin_dest=$content_dest/plugins/join-form

clean $theme_dest
deploy_all packages/theme/dist $theme_dest

clean $plugin_dest
deploy packages/join-block/build $plugin_dest
deploy packages/join-block/lib $plugin_dest
deploy packages/join-block/vendor $plugin_dest
deploy packages/join-block/block.json $plugin_dest
deploy packages/join-block/join.php $plugin_dest
