"""Run concurrency regressions against the explicitly owned synthetic WordPress lab."""

from concurrent.futures import ThreadPoolExecutor
import argparse
import json
import os
from pathlib import Path
import subprocess


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--lab", type=Path, required=True)
    parser.add_argument("--wp-cli", type=Path, required=True)
    args = parser.parse_args()
    lab = args.lab.resolve()
    assert lab.parent == Path("/tmp") and not args.lab.is_symlink()
    assert json.loads((lab / "state.json").read_text())["owner"] == "component-migration"
    env = dict(os.environ)
    env["COMPONENT_LAB_STATE"] = str(lab / "migration-fixtures.json")
    state_path = lab / "concurrency-state.json"
    env["COMPONENT_LAB_CONCURRENT"] = str(state_path)
    worker = Path(__file__).resolve().parent.parent / "tests/wp-concurrency.php"
    command = [
        "php",
        str(args.wp_cli),
        "--skip-packages",
        "--no-color",
        "--path=" + str(lab / "site"),
        "eval-file",
        str(worker),
    ]

    def run(mode, number=0):
        worker_env = dict(env, COMPONENT_LAB_WORKER=mode, COMPONENT_LAB_WORKER_ID=str(number))
        result = subprocess.run(command, env=worker_env, capture_output=True, text=True, check=True)
        return json.loads(result.stdout)

    run("prepare")
    checks = []
    with ThreadPoolExecutor(max_workers=2) as pool:
        likes = list(pool.map(lambda number: run("like", number), [1, 2]))
        assert all(result.get("count") == 1 for result in likes), likes
        assert sorted(result["already_liked"] for result in likes) == [
            False,
            True,
        ], likes
        assert run("inspect")["count"] == 1
        checks.append("Parallel likes commit exactly one count")
        comments = list(pool.map(lambda number: run("create", number), [1, 2]))
        assert comments[0]["id"] == comments[1]["id"], comments
        checks.append("Parallel same-UUID comment creation stores one record")
        state = json.loads(state_path.read_text())
        state.update(id=comments[0]["id"], version=comments[0]["version"])
        state_path.write_text(json.dumps(state) + "\n")
        edits = list(pool.map(lambda number: run("edit", number), [1, 2]))
        assert sorted(result.get("status", 200) for result in edits) == [
            200,
            409,
        ], edits
        checks.append("Parallel edits with same version admit one and reject stale writer")
    run("cleanup")
    output = {"count": len(checks), "checks": checks}
    (lab / "concurrency-results.json").write_text(json.dumps(output, indent=2) + "\n")
    print(json.dumps(output, indent=2))


if __name__ == "__main__":
    main()
