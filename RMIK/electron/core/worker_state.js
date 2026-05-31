let worker_proc_ref = null

export function set_worker_proc_ref(proc)
{
    worker_proc_ref = proc
}

export function get_worker_proc_ref()
{
    return worker_proc_ref
}
