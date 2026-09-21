import argparse
import json
import pathlib
import subprocess
import time

parser = argparse.ArgumentParser()
parser.add_argument('label')
parser.add_argument('command', nargs=argparse.REMAINDER)
args = parser.parse_args()
root = pathlib.Path(__file__).resolve().parent
container = subprocess.check_output(['docker', 'compose', 'ps', '-q', 'app'], text=True).strip()
container = subprocess.check_output(['docker', 'inspect', '-f', '{{.Id}}', container], text=True).strip()


def competing_processes(include_own=False):
    result = []
    for process in pathlib.Path('/proc').glob('[0-9]*'):
        try:
            comm = (process / 'comm').read_text().strip()
            command = (process / 'cmdline').read_bytes().replace(b'\0', b' ').decode(errors='replace')
            if comm.startswith('php') and any(name in command for name in ('phpunit', 'phpbench', 'run-workload.php')):
                if include_own or container not in (process / 'cgroup').read_text():
                    result.append({'pid': int(process.name), 'command': command})
        except (FileNotFoundError, PermissionError, ProcessLookupError):
            pass
    return result


busy = competing_processes(include_own=True)
if busy:
    raise SystemExit('Refusing timing while external workloads run: ' + json.dumps(busy))
subprocess.run(['docker', 'ps', '--format', '{{.Names}} {{.Status}}'], check=True)
start = time.monotonic()
contamination = []
with (root / (args.label + '.log')).open('w') as output:
    process = subprocess.Popen(args.command, stdout=output, stderr=subprocess.STDOUT)
    while process.poll() is None:
        contamination.extend(competing_processes())
        try:
            process.wait(timeout=1)
        except subprocess.TimeoutExpired:
            pass
result = {'label': args.label, 'command': args.command, 'seconds': time.monotonic() - start,
          'exit_code': process.returncode, 'contamination': contamination,
          'accepted': not contamination and process.returncode == 0}
(root / (args.label + '.json')).write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps(result))
raise SystemExit(process.returncode)
