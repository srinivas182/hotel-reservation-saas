import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type Tool = 'pen' | 'box' | 'arrow';

/**
 * Draw on a screenshot (pen, box, arrow) before sending it to support.
 * Returns the annotated picture as a PNG file.
 */
export function ScreenshotAnnotator({
    file,
    onDone,
}: {
    file: File;
    onDone: (annotated: File) => void;
}) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const [tool, setTool] = useState<Tool>('box');
    const start = useRef<{ x: number; y: number } | null>(null);
    const snapshot = useRef<ImageData | null>(null);

    useEffect(() => {
        const image = new Image();
        const url = URL.createObjectURL(file);
        image.onload = () => {
            const c = canvas.current;
            if (!c) {
                return;
            }
            const scale = Math.min(1, 1600 / image.width);
            c.width = image.width * scale;
            c.height = image.height * scale;
            c.getContext('2d')?.drawImage(image, 0, 0, c.width, c.height);
            URL.revokeObjectURL(url);
        };
        image.src = url;
    }, [file]);

    const point = (e: React.PointerEvent<HTMLCanvasElement>) => {
        const c = e.currentTarget;
        const rect = c.getBoundingClientRect();

        return {
            x: ((e.clientX - rect.left) * c.width) / rect.width,
            y: ((e.clientY - rect.top) * c.height) / rect.height,
        };
    };

    const ctx = () => {
        const c = canvas.current?.getContext('2d');
        if (c) {
            c.strokeStyle = '#ef4444';
            c.fillStyle = '#ef4444';
            c.lineWidth = Math.max(3, (canvas.current?.width ?? 800) / 300);
            c.lineCap = 'round';
        }

        return c;
    };

    const shape = (to: { x: number; y: number }) => {
        const c = ctx();
        const from = start.current;
        if (!c || !from) {
            return;
        }
        if (snapshot.current) {
            c.putImageData(snapshot.current, 0, 0);
        }
        if (tool === 'box') {
            c.strokeRect(from.x, from.y, to.x - from.x, to.y - from.y);
        } else if (tool === 'arrow') {
            const angle = Math.atan2(to.y - from.y, to.x - from.x);
            const head = c.lineWidth * 5;
            c.beginPath();
            c.moveTo(from.x, from.y);
            c.lineTo(to.x, to.y);
            c.stroke();
            c.beginPath();
            c.moveTo(to.x, to.y);
            c.lineTo(
                to.x - head * Math.cos(angle - 0.5),
                to.y - head * Math.sin(angle - 0.5),
            );
            c.lineTo(
                to.x - head * Math.cos(angle + 0.5),
                to.y - head * Math.sin(angle + 0.5),
            );
            c.closePath();
            c.fill();
        }
    };

    return (
        <div className="flex flex-col gap-2">
            <div
                className="flex flex-wrap gap-2"
                role="toolbar"
                aria-label={t('Drawing tools')}
            >
                {(['box', 'arrow', 'pen'] as Tool[]).map((k) => (
                    <Button
                        key={k}
                        type="button"
                        size="sm"
                        variant={tool === k ? 'default' : 'outline'}
                        onClick={() => setTool(k)}
                        aria-pressed={tool === k}
                    >
                        {{ box: t('Box'), arrow: t('Arrow'), pen: t('Pen') }[k]}
                    </Button>
                ))}
                <Button
                    type="button"
                    size="sm"
                    onClick={() =>
                        canvas.current?.toBlob((blob) => {
                            if (blob) {
                                onDone(
                                    new File([blob], 'screenshot.png', {
                                        type: 'image/png',
                                    }),
                                );
                            }
                        }, 'image/png')
                    }
                >
                    {t('Use this picture')}
                </Button>
            </div>
            <canvas
                ref={canvas}
                aria-label={t('Screenshot to annotate')}
                className="max-h-[60vh] w-full touch-none rounded-md border object-contain"
                onPointerDown={(e) => {
                    start.current = point(e);
                    const c = ctx();
                    if (c && canvas.current) {
                        snapshot.current = c.getImageData(
                            0,
                            0,
                            canvas.current.width,
                            canvas.current.height,
                        );
                        if (tool === 'pen') {
                            c.beginPath();
                            c.moveTo(start.current.x, start.current.y);
                        }
                    }
                    e.currentTarget.setPointerCapture(e.pointerId);
                }}
                onPointerMove={(e) => {
                    if (!start.current) {
                        return;
                    }
                    const p = point(e);
                    if (tool === 'pen') {
                        const c = ctx();
                        c?.lineTo(p.x, p.y);
                        c?.stroke();
                    } else {
                        shape(p);
                    }
                }}
                onPointerUp={() => {
                    start.current = null;
                    snapshot.current = null;
                }}
            />
        </div>
    );
}
