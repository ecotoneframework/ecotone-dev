# Saga - Stateful Workflow

This is example of Workflow which is stateful

# Example 

This is an example of Order Processing Workflow.  
In this example our Workflow will be responsible for processing the Order.  
The process will start when Order was placed and will trigger an automatic payment.  
If payment was successful then the order process will marked as ready to be shipped.      
If payment failed however, it will retried after one hour.  
If the retried failed it will cancel the order.  