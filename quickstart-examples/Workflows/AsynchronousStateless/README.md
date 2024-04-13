# Asynchronous Stateless Workflows

This is example of Workflow which is asynchronous and stateless.     
All the steps are executed in defined order.

# Example 

In this example we will simulate validating image format, resizing it and then uploading to external storage.  
The workflow consists of three steps: `validate`, `resize` and `upload`.  
We want to execute `validate` step synchronously to stop the action directly if file is not valid.  
Then `resize` and `upload` steps will be done asynchronously together.