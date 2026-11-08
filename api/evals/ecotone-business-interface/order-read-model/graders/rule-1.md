---
type: llm
---
PASS if steps 1-4 are methods of a PHP `interface` carrying `#[DbalQuery]` / `#[DbalWrite]`, step 5 is an `interface` method carrying `#[BusinessMethod]`, and no class implementing either interface (and no hand-written `executeQuery`/`DB::select` for those methods) is written. FAIL if the answer writes a class that implements the query interface or executes the SQL itself.
