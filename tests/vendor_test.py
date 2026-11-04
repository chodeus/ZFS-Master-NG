"""vendor.py prepare(): what each SweetAlert2 update is allowed to ship."""
import importlib.util
import sys
from pathlib import Path

script = Path(sys.argv[1] if len(sys.argv) > 1 else Path(__file__).resolve().parents[1] / '.github/scripts/vendor.py')
spec = importlib.util.spec_from_file_location('vendor', script)
vendor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(vendor)


def refused(src, data):
    try:
        vendor.prepare('sweetalert2', src, data)
    except SystemExit as error:
        return str(error)
    return None


exports = b'this.swal=this.sweetAlert=this.Swal=this.SweetAlert=Sweetalert2;'
assert vendor.prepare('sweetalert2', 'dist/sweetalert2.all.min.js', exports) == b'this.Swal2=this.SweetAlert=Sweetalert2;', 'the export must become Swal2'
assert refused('dist/sweetalert2.all.min.js', b'void 0;') and 'export' in refused('dist/sweetalert2.all.min.js', b'void 0;'), 'a missing export must be refused'
assert vendor.prepare('sweetalert2', 'dist/sweetalert2.min.css', b'div:where(.swal2-container) button{color:red}') == b'div.swal2-container button{color:red}', ':where() must be given weight'
assert 'nested' in (refused('dist/sweetalert2.min.css', b'div:where(.swal2-popup:not(.swal2-toast)) button{color:red}') or ''), 'a nested :where() must be refused'
assert 'audio' in (refused('dist/sweetalert2.min.css', b'document.createElement("audio")') or ''), 'audio code must be refused'
print('vendor prepare tests passed')
