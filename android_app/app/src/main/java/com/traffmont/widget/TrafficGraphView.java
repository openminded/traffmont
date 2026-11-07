package com.traffmont.widget;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.CornerPathEffect;
import android.graphics.LinearGradient;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.RectF;
import android.graphics.Shader;
import android.util.AttributeSet;
import android.view.View;

import androidx.annotation.Nullable;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Locale;

public class TrafficGraphView extends View {

    private static final int MAX_SAMPLES = 45;

    private final List<Double> rxHistory = Collections.synchronizedList(new ArrayList<>());
    private final List<Double> txHistory = Collections.synchronizedList(new ArrayList<>());

    private double peakRxBps = 0;
    private double peakTxBps = 0;
    private boolean isDown = false;

    // Paints
    private Paint bgPaint;
    private Paint gridPaint;
    private Paint rxLinePaint;
    private Paint rxFillPaint;
    private Paint txLinePaint;
    private Paint txFillPaint;
    private Paint downAlertPaint;

    public TrafficGraphView(Context context) {
        super(context);
        init();
    }

    public TrafficGraphView(Context context, @Nullable AttributeSet attrs) {
        super(context, attrs);
        init();
    }

    public TrafficGraphView(Context context, @Nullable AttributeSet attrs, int defStyleAttr) {
        super(context, attrs, defStyleAttr);
        init();
    }

    private void init() {
        bgPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        bgPaint.setColor(0xFF0F172A); // Dark Slate Slate-900
        bgPaint.setStyle(Paint.Style.FILL);

        gridPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        gridPaint.setColor(0x2694A3B8); // 15% opacity slate
        gridPaint.setStrokeWidth(1.5f);
        gridPaint.setStyle(Paint.Style.STROKE);

        // RX (Download) - Cyan
        rxLinePaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        rxLinePaint.setColor(0xFF38BDF8); // Tailwind Sky-400
        rxLinePaint.setStrokeWidth(3.0f);
        rxLinePaint.setStyle(Paint.Style.STROKE);
        rxLinePaint.setStrokeCap(Paint.Cap.ROUND);
        rxLinePaint.setStrokeJoin(Paint.Join.ROUND);
        rxLinePaint.setPathEffect(new CornerPathEffect(8));

        rxFillPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        rxFillPaint.setStyle(Paint.Style.FILL);

        // TX (Upload) - Magenta / Purple
        txLinePaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        txLinePaint.setColor(0xFFC084FC); // Tailwind Purple-400
        txLinePaint.setStrokeWidth(2.2f);
        txLinePaint.setStyle(Paint.Style.STROKE);
        txLinePaint.setStrokeCap(Paint.Cap.ROUND);
        txLinePaint.setStrokeJoin(Paint.Join.ROUND);
        txLinePaint.setPathEffect(new CornerPathEffect(8));

        txFillPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        txFillPaint.setStyle(Paint.Style.FILL);

        downAlertPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        downAlertPaint.setColor(0xFFF87171);
        downAlertPaint.setTextAlign(Paint.Align.CENTER);
        downAlertPaint.setFakeBoldText(true);

        // Prepopulate with a baseline of zeroes
        for (int i = 0; i < MAX_SAMPLES; i++) {
            rxHistory.add(0.0);
            txHistory.add(0.0);
        }
    }

    public void setDown(boolean down) {
        this.isDown = down;
        postInvalidate();
    }

    public void addSample(double rxBps, double txBps) {
        synchronized (rxHistory) {
            rxHistory.add(Math.max(0, rxBps));
            if (rxHistory.size() > MAX_SAMPLES) {
                rxHistory.remove(0);
            }
            if (rxBps > peakRxBps) peakRxBps = rxBps;
        }

        synchronized (txHistory) {
            txHistory.add(Math.max(0, txBps));
            if (txHistory.size() > MAX_SAMPLES) {
                txHistory.remove(0);
            }
            if (txBps > peakTxBps) peakTxBps = txBps;
        }

        postInvalidate();
    }

    public double getPeakRx() {
        return peakRxBps;
    }

    public double getPeakTx() {
        return peakTxBps;
    }

    public void resetPeaks() {
        peakRxBps = 0;
        peakTxBps = 0;
        postInvalidate();
    }

    public static String formatSpeed(double bps) {
        if (bps < 1000) {
            return String.format(Locale.US, "%.2f bps", bps);
        } else if (bps < 1000000) {
            return String.format(Locale.US, "%.1f Kbps", bps / 1000.0);
        } else if (bps < 1000000000) {
            return String.format(Locale.US, "%.1f Mbps", bps / 1000000.0);
        } else {
            return String.format(Locale.US, "%.2f Gbps", bps / 1000000000.0);
        }
    }

    @Override
    protected void onDraw(Canvas canvas) {
        super.onDraw(canvas);

        float w = getWidth();
        float h = getHeight();
        if (w <= 0 || h <= 0) return;

        // Draw rounded background container
        RectF rect = new RectF(0, 0, w, h);
        bgPaint.setColor(isDown ? 0xFF1C0D13 : 0xFF0F172A);
        canvas.drawRoundRect(rect, 12, 12, bgPaint);

        // Draw subtle grid lines (middle line)
        float midY = h * 0.5f;
        gridPaint.setColor(isDown ? 0x33EF4444 : 0x2694A3B8);
        canvas.drawLine(8, midY, w - 8, midY, gridPaint);

        if (isDown) {
            downAlertPaint.setTextSize(h * 0.26f);
            canvas.drawText("⚠️ LINK DOWN / OFFLINE", w * 0.5f, h * 0.58f, downAlertPaint);

            // Red emergency flatline at bottom
            Paint redLine = new Paint(Paint.ANTI_ALIAS_FLAG);
            redLine.setColor(0xFFEF4444);
            redLine.setStrokeWidth(3.0f);
            canvas.drawLine(0, h - 4, w, h - 4, redLine);
            return;
        }

        List<Double> rxCopy;
        List<Double> txCopy;
        synchronized (rxHistory) {
            rxCopy = new ArrayList<>(rxHistory);
        }
        synchronized (txHistory) {
            txCopy = new ArrayList<>(txHistory);
        }

        int count = rxCopy.size();
        if (count < 2) return;

        // Find max scale
        double maxVal = 1000.0; // minimum scale 1 Kbps
        for (double v : rxCopy) if (v > maxVal) maxVal = v;
        for (double v : txCopy) if (v > maxVal) maxVal = v;
        maxVal *= 1.15; // 15% headroom

        float padBottom = 4;
        float padTop = 6;
        float graphHeight = h - padTop - padBottom;
        float stepX = w / (count - 1);

        // 1. Draw RX (Download) Area Fill & Line
        Path rxPath = new Path();
        Path rxArea = new Path();
        rxArea.moveTo(0, h - padBottom);

        for (int i = 0; i < count; i++) {
            float x = i * stepX;
            float ratio = (float) (rxCopy.get(i) / maxVal);
            if (ratio > 1f) ratio = 1f;
            float y = (h - padBottom) - (ratio * graphHeight);

            if (i == 0) {
                rxPath.moveTo(x, y);
                rxArea.lineTo(x, y);
            } else {
                rxPath.lineTo(x, y);
                rxArea.lineTo(x, y);
            }
        }
        rxArea.lineTo(w, h - padBottom);
        rxArea.close();

        // Cyan Gradient Fill
        LinearGradient rxGrad = new LinearGradient(
                0, padTop, 0, h - padBottom,
                0x5038BDF8, 0x0038BDF8, Shader.TileMode.CLAMP
        );
        rxFillPaint.setShader(rxGrad);
        canvas.drawPath(rxArea, rxFillPaint);
        canvas.drawPath(rxPath, rxLinePaint);

        // 2. Draw TX (Upload) Line & Fill
        Path txPath = new Path();
        Path txArea = new Path();
        txArea.moveTo(0, h - padBottom);

        for (int i = 0; i < count; i++) {
            float x = i * stepX;
            float ratio = (float) (txCopy.get(i) / maxVal);
            if (ratio > 1f) ratio = 1f;
            float y = (h - padBottom) - (ratio * graphHeight);

            if (i == 0) {
                txPath.moveTo(x, y);
                txArea.lineTo(x, y);
            } else {
                txPath.lineTo(x, y);
                txArea.lineTo(x, y);
            }
        }
        txArea.lineTo(w, h - padBottom);
        txArea.close();

        LinearGradient txGrad = new LinearGradient(
                0, padTop, 0, h - padBottom,
                0x30C084FC, 0x00C084FC, Shader.TileMode.CLAMP
        );
        txFillPaint.setShader(txGrad);
        canvas.drawPath(txArea, txFillPaint);
        canvas.drawPath(txPath, txLinePaint);
    }

    /**
     * Generates a Bitmap representation of the sparkline chart for Home Screen Widgets (RemoteViews).
     */
    public static Bitmap generateGraphBitmap(int w, int h, List<Double> rxList, List<Double> txList, boolean isDown) {
        if (w <= 0) w = 320;
        if (h <= 0) h = 80;

        Bitmap bitmap = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888);
        Canvas canvas = new Canvas(bitmap);

        Paint bg = new Paint(Paint.ANTI_ALIAS_FLAG);
        bg.setColor(isDown ? 0xFF1C0D13 : 0xFF0F172A);
        RectF r = new RectF(0, 0, w, h);
        canvas.drawRoundRect(r, 10, 10, bg);

        Paint grid = new Paint(Paint.ANTI_ALIAS_FLAG);
        grid.setColor(isDown ? 0x33EF4444 : 0x2694A3B8);
        grid.setStrokeWidth(1.2f);
        canvas.drawLine(4, h * 0.5f, w - 4, h * 0.5f, grid);

        if (isDown) {
            Paint alertText = new Paint(Paint.ANTI_ALIAS_FLAG);
            alertText.setColor(0xFFF87171);
            alertText.setTextSize(h * 0.28f);
            alertText.setTextAlign(Paint.Align.CENTER);
            alertText.setFakeBoldText(true);
            canvas.drawText("⚠️ LINK DOWN / OFFLINE", w * 0.5f, h * 0.58f, alertText);

            Paint redLine = new Paint(Paint.ANTI_ALIAS_FLAG);
            redLine.setColor(0xFFEF4444);
            redLine.setStrokeWidth(3.0f);
            canvas.drawLine(0, h - 3, w, h - 3, redLine);
            return bitmap;
        }

        if (rxList == null || rxList.size() < 2) return bitmap;

        int count = rxList.size();
        double maxVal = 1000.0;
        for (double v : rxList) if (v > maxVal) maxVal = v;
        if (txList != null) {
            for (double v : txList) if (v > maxVal) maxVal = v;
        }
        maxVal *= 1.15;

        float padBottom = 3;
        float padTop = 5;
        float graphHeight = h - padTop - padBottom;
        float stepX = (float) w / (count - 1);

        // RX
        Path rxPath = new Path();
        Path rxArea = new Path();
        rxArea.moveTo(0, h - padBottom);
        for (int i = 0; i < count; i++) {
            float x = i * stepX;
            float ratio = (float) (rxList.get(i) / maxVal);
            if (ratio > 1f) ratio = 1f;
            float y = (h - padBottom) - (ratio * graphHeight);
            if (i == 0) {
                rxPath.moveTo(x, y);
                rxArea.lineTo(x, y);
            } else {
                rxPath.lineTo(x, y);
                rxArea.lineTo(x, y);
            }
        }
        rxArea.lineTo(w, h - padBottom);
        rxArea.close();

        Paint rxFill = new Paint(Paint.ANTI_ALIAS_FLAG);
        rxFill.setShader(new LinearGradient(0, padTop, 0, h - padBottom, 0x5038BDF8, 0x0038BDF8, Shader.TileMode.CLAMP));
        canvas.drawPath(rxArea, rxFill);

        Paint rxLine = new Paint(Paint.ANTI_ALIAS_FLAG);
        rxLine.setColor(0xFF38BDF8);
        rxLine.setStrokeWidth(2.5f);
        rxLine.setStyle(Paint.Style.STROKE);
        rxLine.setStrokeCap(Paint.Cap.ROUND);
        rxLine.setStrokeJoin(Paint.Join.ROUND);
        canvas.drawPath(rxPath, rxLine);

        // TX
        if (txList != null && txList.size() == count) {
            Path txPath = new Path();
            for (int i = 0; i < count; i++) {
                float x = i * stepX;
                float ratio = (float) (txList.get(i) / maxVal);
                if (ratio > 1f) ratio = 1f;
                float y = (h - padBottom) - (ratio * graphHeight);
                if (i == 0) {
                    txPath.moveTo(x, y);
                } else {
                    txPath.lineTo(x, y);
                }
            }
            Paint txLine = new Paint(Paint.ANTI_ALIAS_FLAG);
            txLine.setColor(0xFFC084FC);
            txLine.setStrokeWidth(2.0f);
            txLine.setStyle(Paint.Style.STROKE);
            txLine.setStrokeCap(Paint.Cap.ROUND);
            txLine.setStrokeJoin(Paint.Join.ROUND);
            canvas.drawPath(txPath, txLine);
        }

        return bitmap;
    }

    public static Bitmap generateGraphBitmap(int w, int h, List<Double> rxList, List<Double> txList) {
        return generateGraphBitmap(w, h, rxList, txList, false);
    }
}
