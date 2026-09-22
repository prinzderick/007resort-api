// Program.cs assigns the static Serilog `Log.Logger` bootstrap logger on every host build.
// Building several WebApplicationFactory-backed hosts concurrently races on that shared static
// and throws "The logger is already frozen." Running this assembly's test classes serially
// avoids it; it does not affect the meaningfulness of any test.
[assembly: CollectionBehavior(DisableTestParallelization = true)]
