import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactElement } from 'react';

import { toast } from '@/components/ui/toast';
import AppLayout from '@/layouts/app-layout';
import { now } from '@/routes';
import { closeForToday } from '@/routes/now';
import {
    check as itemCheck,
    show as itemShow,
} from '@/routes/objectives/items';

type Item = {
    key: string;
    title: string;
    two_minute_version: string;
    completed_at: string | null;
    evidence: {
        text: string;
        link: string | null;
        image_url: string | null;
    } | null;
};

type Props = {
    objective: { key: string; title: string };
    item: Item;
    opened: { key: string; title: string }[];
    path: { title: string; completed_at: string }[];
};

/**
 * Screen 12 (mockup visual/screens/12-milestone-summit.html): the milestone
 * completion moment. Evidence (text/link/optional image) is required once;
 * once given, this same page shows what it opened and the path of completed
 * items that led here (reviews spec "A Milestone Summit Records Evidence").
 */
export default function MilestoneSummit({
    objective,
    item,
    opened,
    path,
}: Props) {
    const isDone = item.completed_at !== null;

    return (
        <div className="mos-s12">
            <Head title={`Cumbre: ${item.title}`} />

            <section className="summit-hero">
                <p className="meta">
                    {isDone ? 'Llegaste al hito.' : 'A un paso del hito.'}
                </p>
                <h1 className="sm-name">{item.title}</h1>
            </section>

            <div className="sm-grid">
                {isDone ? (
                    <div className="card" style={{ padding: 24 }}>
                        <h2>Evidencia del hito</h2>
                        <p>{item.evidence?.text}</p>
                        {item.evidence?.link && (
                            <p className="small">
                                <a
                                    href={item.evidence.link}
                                    target="_blank"
                                    rel="noreferrer noopener"
                                >
                                    {item.evidence.link}
                                </a>
                            </p>
                        )}
                        {item.evidence?.image_url && (
                            <img
                                src={item.evidence.image_url}
                                alt=""
                                style={{
                                    marginTop: 12,
                                    maxWidth: '100%',
                                    borderRadius: 10,
                                }}
                            />
                        )}
                    </div>
                ) : (
                    <EvidenceForm objective={objective} item={item} />
                )}

                <div>
                    <section className="card" style={{ padding: 24 }}>
                        <h2>Esto abrió</h2>
                        {opened.length === 0 ? (
                            <p className="meta">
                                Todavía no hay nada que se abra desde acá.
                            </p>
                        ) : (
                            opened.map((node) => (
                                <div key={node.key} className="path-row">
                                    <span>{node.title}</span>
                                </div>
                            ))
                        )}
                    </section>
                    <section
                        className="card"
                        style={{ padding: 24, marginTop: 20 }}
                    >
                        <h2>El camino hasta acá</h2>
                        {path.length === 0 ? (
                            <p className="meta">
                                Es la primera marca del camino.
                            </p>
                        ) : (
                            path.map((step, index) => (
                                <div key={index} className="path-row">
                                    <span>{step.title}</span>
                                    <span className="meta">
                                        {new Date(
                                            step.completed_at,
                                        ).toLocaleDateString('es')}
                                    </span>
                                </div>
                            ))
                        )}
                    </section>
                </div>
            </div>

            <p style={{ marginTop: 24 }}>
                <Link href={itemShow([objective.key, item.key])}>
                    Ver detalle de la tarea
                </Link>
            </p>
        </div>
    );
}

function EvidenceForm({
    objective,
    item,
}: {
    objective: Props['objective'];
    item: Item;
}) {
    const [evidence, setEvidence] = useState('');
    const [link, setLink] = useState('');
    const [image, setImage] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = (closeToday: boolean) => {
        setProcessing(true);

        // Only forced into multipart/form-data when an image is actually
        // attached: a plain POST is simpler and more compatible when there
        // is nothing to upload (screen 12's evidence is text/link most of
        // the time; the image is optional).
        const data: { evidence: string; link: string | null; image?: File } = {
            evidence,
            link: link || null,
        };

        if (image !== null) {
            data.image = image;
        }

        router.post(itemCheck([objective.key, item.key]).url, data, {
            forceFormData: image !== null,
            preserveScroll: true,
            onSuccess: () => {
                toast('Hito marcado.');

                if (closeToday) {
                    router.post(
                        closeForToday().url,
                        { item: item.key },
                        {
                            onSuccess: () => router.visit(now().url),
                        },
                    );
                }
            },
            onError: (validationErrors) =>
                setErrors(validationErrors as Record<string, string>),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <form
            className="card"
            style={{ padding: 24 }}
            onSubmit={(event) => {
                event.preventDefault();
                submit(true);
            }}
        >
            <h2>Evidencia del hito</h2>
            <p className="lede">
                Una línea basta. Queda guardada en el hito y en Revisiones.
            </p>
            <label className="fld">
                <span className="l">Qué quedó hecho</span>
                <textarea
                    className="in"
                    name="evidence"
                    rows={3}
                    autoFocus
                    value={evidence}
                    onChange={(event) => setEvidence(event.target.value)}
                />
            </label>
            <label className="fld">
                <span className="l">
                    Enlace <em>(opcional)</em>
                </span>
                <input
                    className="in"
                    name="link"
                    type="url"
                    value={link}
                    onChange={(event) => setLink(event.target.value)}
                />
            </label>
            <label className="fld">
                <span className="l">
                    Imagen <em>(opcional, hasta 5 MB)</em>
                </span>
                <input
                    className="in"
                    name="image"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    onChange={(event) =>
                        setImage(event.target.files?.[0] ?? null)
                    }
                />
            </label>
            {Object.values(errors).length > 0 && (
                <p className="err" role="alert">
                    {Object.values(errors)[0]}
                </p>
            )}
            <div className="tri-actions">
                <button
                    className="btn btn-primary"
                    type="submit"
                    disabled={processing || evidence.trim() === ''}
                >
                    Guardar y cerrar por hoy
                </button>
                <button
                    className="btn btn-outline"
                    type="button"
                    disabled={processing || evidence.trim() === ''}
                    onClick={() => submit(false)}
                >
                    Guardar y seguir
                </button>
            </div>
            {evidence.trim() === '' && (
                <p className="disabled-why" style={{ marginTop: 8 }}>
                    Escribí una línea de evidencia para guardar el hito.
                </p>
            )}
        </form>
    );
}

MilestoneSummit.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
