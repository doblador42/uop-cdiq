package gr.uop.network;

import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.ThreadPoolExecutor;
import java.util.concurrent.TimeUnit;

public class TaskProcessor {

    private ThreadPoolExecutor tpe;

    public TaskProcessor() {
        tpe = new ThreadPoolExecutor(
            1,
            1,
            0L,
            TimeUnit.MILLISECONDS,
            new LinkedBlockingQueue<>()
        );
    }

    public void process(Task task) {
        tpe.execute(task);
    }

    public void shutdown() {
        tpe.shutdown();
        try {
            if (!tpe.awaitTermination(5, TimeUnit.SECONDS)) {
                tpe.shutdownNow();
                if (!tpe.awaitTermination(5, TimeUnit.SECONDS)) {
                    System.err.println("Task processor unable to shutdown!");
                }
            }
        } catch (InterruptedException e) {
            System.err.println("Task processor got interrupted! (should not happen)");
        }
    }

}
