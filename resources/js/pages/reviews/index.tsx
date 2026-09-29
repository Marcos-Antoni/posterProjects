import { Head, Link } from '@inertiajs/react';
import type { ReactElement } from 'react';

import AppLayout from '@/layouts/app-layout';
import { show as weeklyReviewForm } from '@/routes/reviews/weekly';

type ReviewEntry = {
    id: number;
    kind: 'weekly' | 'objective';
    kind_label: string;
    objective_title: string | null;
    answers: Record<string, string>;
    created_at: string | null;
};

type Props = {
    reviews: ReviewEntry[];
};

/**
 * Screen 15 (mockup visual/screens/15-reviews-history.html): every weekly
 * review, milestone summit and learning review, chronological, newest
 * first (reviews spec "Reviews Are Kept As History"). Nothing here is
 * deletable.
 */
export default function ReviewsIndex({ reviews }: Props) {
    return (
        <div className="mos-s15">
            <div className="head">
                <div>
                    <h1 className="h1">Revisiones</h1>
                    <p className="lede">
                        Lo que anotaste al revisar la semana o al cerrar un
                        objetivo, en orden. Nada de esto se borra.
                    </p>
                </div>
                <Link className="btn btn-primary" href={weeklyReviewForm()}>
                    Nueva revisión semanal
                </Link>
            </div>
            <Head title="Revisiones" />

            {reviews.length === 0 ? (
                <p className="meta">Todavía no hay revisiones.</p>
            ) : (
                <div className="card" style={{ padding: 24 }}>
                    {reviews.map((review) => (
                        <div key={review.id} className="rev-row">
                            <p className="meta">
                                <b>{review.kind_label}</b>
                                {review.objective_title
                                    ? ` · ${review.objective_title}`
                                    : ''}
                                {review.created_at
                                    ? ` · ${new Date(review.created_at).toLocaleDateString('es')}`
                                    : ''}
                            </p>
                            <dl>
                                {Object.entries(review.answers).map(
                                    ([key, value]) => (
                                        <div key={key}>
                                            <dt className="sr">{key}</dt>
                                            <dd style={{ margin: '4px 0' }}>
                                                {value}
                                            </dd>
                                        </div>
                                    ),
                                )}
                            </dl>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

ReviewsIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
