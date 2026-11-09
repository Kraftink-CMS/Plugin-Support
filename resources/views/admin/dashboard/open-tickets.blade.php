<x-admin::stat :label="__('support::admin.tickets.open_count')" :value="$count" icon="life-preserver" color="warning"
    :url="route('plugins.support.admin.tickets.index', ['status' => 'active'])" />
