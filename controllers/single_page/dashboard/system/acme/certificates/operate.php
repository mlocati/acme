<?php

namespace Concrete\Package\Acme\Controller\SinglePage\Dashboard\System\Acme\Certificates;

use Acme\Certificate\Renewer;
use Acme\Certificate\RenewerOptions;
use Acme\Certificate\RevocationChecker;
use Acme\Entity\Certificate;
use Acme\Exception\CheckRevocationException;
use Acme\Service\UI;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Http\ResponseFactoryInterface;
use Concrete\Core\Localization\Localization;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Url\Resolver\Manager\ResolverManagerInterface;
use Doctrine\ORM\EntityManagerInterface;

defined('C5_EXECUTE') or die('Access Denied.');

final class Operate extends DashboardPageController
{
    public function view($certificateID = '')
    {
        $certificate = $this->getCertificate($certificateID);
        if ($certificate === null) {
            return $this->buildReturnRedirectResponse();
        }
        if ($certificate->isDisabled()) {
            $this->flash('error', t('The certificate is disabled.'));

            return $this->buildReturnRedirectResponse();
        }
        $this->set('certificate', $certificate);
        $this->set('resolverManager', $this->app->make(ResolverManagerInterface::class));
        $this->set('localization', $this->app->make(Localization::class));
        $this->set('ui', $this->app->make(UI::class));
        $this->requireAsset('javascript', 'vue');
    }

    public function next_step($certificateID = '')
    {
        $certificate = $this->getCertificate($certificateID);
        if ($certificate === null) {
            throw new UserMessageException(t('Unable to find the requested certificate.'));
        }
        if (!$this->token->validate('acme-certificate-nextstep-' . $certificateID)) {
            throw new UserMessageException($this->token->getErrorMessage());
        }
        if ($certificate->isDisabled()) {
            throw new UserMessageException(t('The certificate is disabled.'));
        }
        $post = $this->request->request;
        $renewerOptions = RenewerOptions::create()
            ->setForceCertificateRenewal($post->get('forceRenew'))
            ->setForceActionsExecution($post->get('forceActions'))
        ;
        $renewer = $this->app->make(Renewer::class);
        $renewed = $renewer->nextStep($certificate, $renewerOptions);

        $responseData = [
            'messages' => $renewed->getEntries(),
            'nextStepAfter' => $renewed->getNextStepAfter(),
        ];
        if ($renewed->getNewCertificateInfo() !== null) {
            $responseData['certificateInfo'] = $renewed->getNewCertificateInfo();
        }
        if ($renewed->getOrderOrAuthorizationsRequest() !== null) {
            $responseData['order'] = $renewed->getOrderOrAuthorizationsRequest();
        }

        return $this->app->make(ResponseFactoryInterface::class)->json($responseData);
    }

    public function check_revocation($certificateID = '')
    {
        $certificate = $this->getCertificate($certificateID);
        if ($certificate === null) {
            throw new UserMessageException(t('Unable to find the requested certificate.'));
        }
        if (!$this->token->validate('acme-certificate-checkrevocation-' . $certificateID)) {
            throw new UserMessageException($this->token->getErrorMessage());
        }
        if ($certificate->isDisabled()) {
            throw new UserMessageException(t('The certificate is disabled.'));
        }
        $certificateInfo = $certificate->getCertificateInfo();
        if ($certificateInfo === null) {
            throw new UserMessageException(t('The certificate has not been issued yet'));
        }
        try {
            $status = $this->app->make(RevocationChecker::class)->checkRevocation($certificateInfo);
        } catch (CheckRevocationException $x) {
            throw new UserMessageException($x->getMessage());
        }
        $dh = $this->app->make('date');

        return $this->app->make(ResponseFactoryInterface::class)->json([
            'revoked' => $status->isRevoked(),
            'revokedOn' => $dh->formatDateTime($status->getRevokedOn(), true, true),
            'thisUpdate' => $dh->formatDateTime($status->getThisUpdate(), true, true),
        ]);
    }

    /**
     * @param int|string $certificateID
     * @param bool $flashOnNotFound
     *
     * @return \Acme\Entity\Certificate|null
     */
    private function getCertificate($certificateID, $flashOnNotFound = true)
    {
        $certificateID = (int) $certificateID;
        $certificate = $certificateID === 0 ? null : $this->app->make(EntityManagerInterface::class)->find(Certificate::class, $certificateID);
        if ($certificate !== null) {
            return $certificate;
        }
        if ($certificateID !== 0 && $flashOnNotFound) {
            $this->flash('error', t('Unable to find the requested certificate.'));
        }

        return null;
    }

    /**
     * @return \Concrete\Core\Routing\RedirectResponse
     */
    private function buildReturnRedirectResponse()
    {
        return $this->app->make(ResponseFactoryInterface::class)->redirect(
            $this->app->make(ResolverManagerInterface::class)->resolve(['/dashboard/system/acme/certificates']),
            302
        );
    }
}
